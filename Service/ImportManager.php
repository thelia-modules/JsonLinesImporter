<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service;

use JsonLinesImporter\Dto\ImportParameters;
use JsonLinesImporter\Enum\ImportStatus;
use JsonLinesImporter\Exception\ImportException;
use JsonLinesImporter\JsonLinesImporter;
use JsonLinesImporter\Model\JsonlImport;

/**
 * Creation and removal of imports: the uploaded file is kept out of the web root under a generated name.
 */
final readonly class ImportManager
{
    public const MAX_FILE_BYTES = 20 * 1024 * 1024;

    public static function maxFileBytes(): int
    {
        return min(self::MAX_FILE_BYTES, self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size'));
    }

    /**
     * @throws ImportException when the file is refused
     */
    public function create(string $path, string $originalName, ?string $author, ?ImportParameters $parameters = null): JsonlImport
    {
        if ('jsonl' !== strtolower(pathinfo($originalName, \PATHINFO_EXTENSION))) {
            throw new ImportException('Only .jsonl files are accepted.');
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new ImportException('The file cannot be read.');
        }

        if (filesize($path) > self::maxFileBytes()) {
            throw new ImportException('The file is larger than %size%.', ['%size%' => self::humanSize(self::maxFileBytes())]);
        }

        $directory = JsonLinesImporter::getStorageDirectory();

        if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
            throw new ImportException('The storage directory of the imports cannot be created.');
        }

        $storedName = date('Ymd-His').'-'.bin2hex(random_bytes(6)).'.jsonl';

        if (!copy($path, $directory.$storedName)) {
            throw new ImportException('The file cannot be stored.');
        }

        $import = (new JsonlImport())
            ->setOriginalName(mb_substr(basename($originalName), 0, 255))
            ->setStoredName($storedName)
            ->setAuthor($author)
            ->setStatus(ImportStatus::ANALYSING->value)
            ->setParametersArray(($parameters ?? new ImportParameters())->toArray());
        $import->save();

        return $import;
    }

    public function delete(JsonlImport $import): void
    {
        $path = $import->getStoredPath();

        $import->delete();

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1024 * 1024 ? round($bytes / 1024 / 1024, 1).' MB' : round($bytes / 1024).' KB';
    }

    private static function iniBytes(string $key): int
    {
        $value = trim((string) \ini_get($key));

        if ('' === $value || '0' === $value || '-1' === $value) {
            return \PHP_INT_MAX;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
