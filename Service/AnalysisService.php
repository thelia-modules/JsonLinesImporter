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

/**
 * Step 1: reads the file from where the previous batch stopped and stores every line with its verdict.
 */
final readonly class AnalysisService
{
    public function __construct(
        private JsonLinesReader $reader,
        private LineValidator $validator,
        private ProductWriter $productWriter,
    ) {
    }

    /**
     * @return bool true once the whole file is analysed
     */
    public function analyseBatch(JsonlImport $import, float $deadline): bool
    {
        if (ImportStatus::ANALYSING !== $import->getStatusEnum()) {
            return true;
        }

        $importId = (int) $import->getId();
        $lines = $this->reader->read($import->getStoredPath(), (int) $import->getAnalysisOffset(), (int) $import->getLineCount() + 1);

        foreach ($lines as $line) {
            $import
                ->setAnalysisOffset($line->nextOffset)
                ->setLineCount($line->number);

            if (!$line->isBlank()) {
                $this->analyseLine($import, $importId, $line->number, $line->content);
            }

            if (microtime(true) >= $deadline) {
                $import->save();

                return false;
            }
        }

        $import->setStatus(ImportStatus::ANALYSED->value)->save();

        return true;
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
            ->setPayload($content)
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
            ->filterByPayload('%'.addcslashes(json_encode($variantRef, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), '%_').'%', Criteria::LIKE)
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
