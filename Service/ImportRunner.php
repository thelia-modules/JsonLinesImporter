<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service;

use JsonLinesImporter\Dto\ImportParameters;
use JsonLinesImporter\Enum\ImportStatus;
use JsonLinesImporter\Enum\LineAction;
use JsonLinesImporter\Enum\LineResult;
use JsonLinesImporter\Enum\RunPhase;
use JsonLinesImporter\Exception\ImportException;
use JsonLinesImporter\Model\JsonlImport;
use JsonLinesImporter\Model\JsonlImportLine;
use JsonLinesImporter\Model\JsonlImportLineQuery;
use JsonLinesImporter\Model\JsonlImportQuery;
use JsonLinesImporter\Service\Writer\ProductWriter;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Propel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;
use Thelia\Api\Service\API\ResourceCache;
use Thelia\Core\Event\Image\ImageEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Log\Tlog;
use Thelia\Model\ProductQuery;

/**
 * Step 5: imports the retained lines batch by batch, then the accessories, then clears the caches once.
 *
 * Only one import may run in the shop. An import that received no batch for STALE_AFTER seconds is
 * considered paused. A lock on the import prevents a double call from processing a line twice; with a
 * semaphore store it falls at the end of each request, the state kept in the database covers the gap
 * between two batches.
 */
final readonly class ImportRunner
{
    public const STALE_AFTER = 120;

    private const MAX_BATCH_SECONDS = 30;

    public function __construct(
        private ProductLineFactory $lineFactory,
        private ProductWriter $productWriter,
        private ShopContext $shopContext,
        private LockFactory $lockFactory,
        private ResourceCache $resourceCache,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public static function batchDeadline(): float
    {
        $limit = (int) \ini_get('max_execution_time');
        $seconds = $limit > 0 ? min(self::MAX_BATCH_SECONDS, max(5, $limit - 10)) : self::MAX_BATCH_SECONDS;

        return microtime(true) + $seconds;
    }

    public function runningImport(?int $exceptId = null): ?JsonlImport
    {
        $query = JsonlImportQuery::create()
            ->filterByStatus(ImportStatus::RUNNING->value)
            ->filterByLastBatchAt(new \DateTime(\sprintf('-%d seconds', self::STALE_AFTER)), Criteria::GREATER_EQUAL);

        if (null !== $exceptId) {
            $query->filterById($exceptId, Criteria::NOT_EQUAL);
        }

        return $query->findOne();
    }

    /**
     * @throws ImportException when another import is running
     */
    public function start(JsonlImport $import): void
    {
        $other = $this->runningImport((int) $import->getId());

        if (null !== $other) {
            throw new ImportException(\sprintf('The import #%d is running: wait for its end or pause it.', $other->getId()));
        }

        $this->pauseStaleImports((int) $import->getId());

        if (null === $import->getPhase()) {
            $this->markExcludedLines($import);
            $import->setPhase(RunPhase::LINES->value)->setCursorLine(0);
        }

        $import
            ->setStatus(ImportStatus::RUNNING->value)
            ->setLastBatchAt(new \DateTime())
            ->save();
    }

    public function pause(JsonlImport $import): void
    {
        if (ImportStatus::RUNNING === $import->getStatusEnum()) {
            $import->setStatus(ImportStatus::PAUSED->value)->save();
        }
    }

    /**
     * @return bool true once the import is over
     */
    public function runBatch(JsonlImport $import, float $deadline): bool
    {
        $lock = $this->lockFactory->createLock('jsonl-import-'.$import->getId(), self::MAX_BATCH_SECONDS * 4);

        if (!$lock->acquire()) {
            return false;
        }

        try {
            $import->reload();

            if (ImportStatus::RUNNING !== $import->getStatusEnum()) {
                return ImportStatus::DONE === $import->getStatusEnum();
            }

            Propel::disableInstancePooling();
            ProductQuery::disableVersioning();

            $parameters = ImportParameters::fromArray($import->getParametersArray());
            $defaultLocale = $this->shopContext->defaultLocale();

            while (microtime(true) < $deadline) {
                $line = $this->nextLine($import);

                if (null === $line) {
                    if (RunPhase::LINES === $import->getPhaseEnum()) {
                        $import->setPhase(RunPhase::ACCESSORIES->value)->setCursorLine(0)->save();

                        continue;
                    }

                    $this->finish($import);

                    return true;
                }

                $import->setCurrentRef($line->getRef());

                if (RunPhase::LINES === $import->getPhaseEnum()) {
                    $this->importLine($import, $line, $parameters, $defaultLocale);
                } else {
                    $this->importAccessories($line, $parameters, $defaultLocale);
                }

                $import
                    ->setCursorLine($line->getLineNumber())
                    ->setLastBatchAt(new \DateTime())
                    ->save();
            }

            return false;
        } finally {
            Propel::enableInstancePooling();
            ProductQuery::enableVersioning();
            $lock->release();
        }
    }

    private function nextLine(JsonlImport $import): ?JsonlImportLine
    {
        $query = JsonlImportLineQuery::create()
            ->filterByImportId($import->getId())
            ->filterByLineNumber((int) $import->getCursorLine(), Criteria::GREATER_THAN)
            ->orderByLineNumber();

        if (RunPhase::LINES === $import->getPhaseEnum()) {
            $query->filterByResult(LineResult::PENDING->value);
        } else {
            $query
                ->filterByResult([LineResult::CREATED->value, LineResult::UPDATED->value], Criteria::IN)
                ->filterByPayload('%"accessories"%', Criteria::LIKE);
        }

        return $query->findOne();
    }

    private function importLine(JsonlImport $import, JsonlImportLine $line, ImportParameters $parameters, string $defaultLocale): void
    {
        $report = new LineReport();

        try {
            $productLine = $this->lineFactory->create((array) $line->getPayloadArray(), $defaultLocale, $parameters);
            $action = $this->productWriter->write($productLine, $parameters, $report);

            $line->setResult(LineAction::CREATE === $action ? LineResult::CREATED->value : LineResult::UPDATED->value);

            if (LineAction::CREATE === $action) {
                $import->setCreatedCount((int) $import->getCreatedCount() + 1);
            } else {
                $import->setUpdatedCount((int) $import->getUpdatedCount() + 1);
            }

            if ($report->hasDeletedProductImages()) {
                $import->setParametersArray([...$import->getParametersArray(), 'clear_image_cache' => true]);
            }
        } catch (\Throwable $exception) {
            Tlog::getInstance()->error(\sprintf('JsonLinesImporter: line %d (%s) failed: %s', $line->getLineNumber(), $line->getRef(), $exception->getMessage()));

            $line
                ->setResult(LineResult::ERROR->value)
                ->setErrorList([$exception->getMessage()]);
            $import->setErrorCount((int) $import->getErrorCount() + 1);
        }

        $line
            ->setWarningList([...$line->getWarningList(), ...$report->warnings()])
            ->save();
    }

    private function importAccessories(JsonlImportLine $line, ImportParameters $parameters, string $defaultLocale): void
    {
        $report = new LineReport();

        $productLine = $this->lineFactory->create((array) $line->getPayloadArray(), $defaultLocale, $parameters);
        $this->productWriter->writeAccessories($productLine, $report);

        if ([] !== $report->warnings()) {
            $line->setWarningList([...$line->getWarningList(), ...$report->warnings()])->save();
        }
    }

    private function markExcludedLines(JsonlImport $import): void
    {
        $parameters = ImportParameters::fromArray($import->getParametersArray());
        $excluded = 0;

        $lines = JsonlImportLineQuery::create()
            ->filterByImportId($import->getId())
            ->filterByValid(1)
            ->filterByResult(LineResult::PENDING->value)
            ->find();

        foreach ($lines as $line) {
            $action = $line->getActionEnum();
            $isExcluded = $parameters->isLineExcluded((int) $line->getLineNumber())
                || (LineAction::CREATE === $action && !$parameters->createProducts)
                || (LineAction::UPDATE === $action && !$parameters->updateProducts);

            if ($isExcluded) {
                $line->setExcluded(1)->setResult(LineResult::EXCLUDED->value)->save();
                ++$excluded;
            }
        }

        $import->setExcludedCount($excluded);
    }

    private function finish(JsonlImport $import): void
    {
        $import
            ->setPhase(RunPhase::FINISHED->value)
            ->setStatus(ImportStatus::DONE->value)
            ->setCurrentRef(null)
            ->save();

        $this->resourceCache->clear();

        if ($import->getParametersArray()['clear_image_cache'] ?? false) {
            try {
                $this->dispatcher->dispatch((new ImageEvent())->setCacheSubdirectory('product'), TheliaEvents::IMAGE_CLEAR_CACHE);
            } catch (\Throwable $exception) {
                Tlog::getInstance()->warning('JsonLinesImporter: product image cache not cleared: '.$exception->getMessage());
            }
        }
    }

    private function pauseStaleImports(int $exceptId): void
    {
        JsonlImportQuery::create()
            ->filterByStatus(ImportStatus::RUNNING->value)
            ->filterById($exceptId, Criteria::NOT_EQUAL)
            ->update(['Status' => ImportStatus::PAUSED->value]);
    }
}
