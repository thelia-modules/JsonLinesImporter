<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service;

use JsonLinesImporter\Enum\ImportStatus;
use JsonLinesImporter\Enum\LineResult;
use JsonLinesImporter\Model\JsonlImport;
use JsonLinesImporter\Model\JsonlImportLine;
use JsonLinesImporter\Model\JsonlImportLineQuery;
use JsonLinesImporter\Reader\JsonLinesReader;
use JsonLinesImporter\Service\Writer\ProductWriter;
use JsonLinesImporter\Validation\LineValidator;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Propel;
use Symfony\Component\Lock\LockFactory;

/**
 * Step 1: reads the file from where the previous batch stopped and stores every line with its verdict.
 */
final readonly class AnalysisService
{
    public function __construct(
        private JsonLinesReader $reader,
        private LineValidator $validator,
        private ProductWriter $productWriter,
        private LockFactory $lockFactory,
    ) {
    }

    /**
     * @return bool true once the whole file is analysed
     */
    public function analyseBatch(JsonlImport $import, float $deadline): bool
    {
        // Two calls at once would read the same lines twice.
        $lock = $this->lockFactory->createLock('jsonl-analysis-'.$import->getId(), 300);

        if (!$lock->acquire()) {
            return false;
        }

        try {
            $import->reload();

            if (ImportStatus::ANALYSING !== $import->getStatusEnum()) {
                return true;
            }

            $importId = (int) $import->getId();
            $lines = $this->reader->read($import->getStoredPath(), (int) $import->getAnalysisOffset(), (int) $import->getLineCount() + 1);
            $con = Propel::getWriteConnection('TheliaMain');

            foreach ($lines as $line) {
                // The line and the offset of the next one are saved together: a batch cut in the
                // middle resumes on the first line not stored, never on a stored one.
                $con->beginTransaction();

                try {
                    if (!$line->isBlank()) {
                        $this->analyseLine($import, $importId, $line->number, $line->content);
                    }

                    $import
                        ->setAnalysisOffset($line->nextOffset)
                        ->setLineCount($line->number)
                        ->save($con);
                    $con->commit();
                } catch (\Throwable $exception) {
                    $con->rollBack();

                    throw $exception;
                }

                if (microtime(true) >= $deadline) {
                    return false;
                }
            }

            $import->setStatus(ImportStatus::ANALYSED->value)->save();

            return true;
        } finally {
            $lock->release();
        }
    }

    private function analyseLine(JsonlImport $import, int $importId, int $number, string $content): void
    {
        $result = $this->validator->validate(
            $content,
            static fn (string $ref): bool => JsonlImportLineQuery::create()->filterByImportId($importId)->filterByValid(1)->filterByRef($ref)->exists(),
            static fn (string $variantRef, string $productRef): bool => self::isVariantRefTakenInFile($importId, $variantRef, $productRef),
        );

        $data = json_decode($content, true);
        $ref = \is_array($data) && \is_scalar($data['ref'] ?? null) ? mb_substr(trim((string) $data['ref']), 0, 255) : null;

        $row = (new JsonlImportLine())
            ->setImportId($importId)
            ->setLineNumber($number)
            ->setRef($ref)
            ->setValid($result->isValid() ? 1 : 0)
            ->setResult($result->isValid() ? LineResult::PENDING->value : LineResult::INVALID->value)
            // Bytes that are not UTF-8 are refused by a strict MySQL: the payload of such a line is scrubbed.
            ->setPayload(mb_check_encoding($content, 'UTF-8') ? $content : mb_scrub($content, 'UTF-8'))
            ->setErrorList($result->errors())
            ->setWarningList($result->warnings());

        if ($result->isValid() && null !== $ref) {
            $row->setAction($this->productWriter->plannedAction($ref)->value);
            $import->setValidCount((int) $import->getValidCount() + 1);
        } else {
            $import->setInvalidCount((int) $import->getInvalidCount() + 1);
        }

        $row->save();
    }

    private static function isVariantRefTakenInFile(int $importId, string $variantRef, string $productRef): bool
    {
        $candidates = JsonlImportLineQuery::create()
            ->filterByImportId($importId)
            ->filterByValid(1)
            ->filterByRef($productRef, Criteria::NOT_EQUAL)
            ->filterByPayload('%'.addcslashes((string) json_encode($variantRef, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), '%_\\').'%', Criteria::LIKE)
            ->find();

        foreach ($candidates as $candidate) {
            $payload = $candidate->getPayloadArray();

            foreach ((array) ($payload['variants'] ?? []) as $variant) {
                if (trim((string) ($variant['ref'] ?? $payload['ref'] ?? '')) === $variantRef) {
                    return true;
                }
            }
        }

        return false;
    }
}
