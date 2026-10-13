<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service;

use JsonLinesImporter\Exception\ImportException;
use JsonLinesImporter\JsonLinesImporter;

/**
 * The directories media given by a path on the server are read from: always inside the import root of the
 * shop (`local/import/`), whatever step 3 or a parameters file declare. Without it, an administrator of the
 * module could publish as a product document any file the web server reads.
 */
final readonly class MediaSourceDirectory
{
    public function __construct(
        private ?string $root = null,
    ) {
    }

    public function root(): string
    {
        return rtrim($this->root ?? JsonLinesImporter::getMediaSourceRoot(), \DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR;
    }

    /**
     * @param string|null $directory relative to the root, or absolute inside it
     *
     * @return string|null the real path of the directory, null when none is declared
     *
     * @throws ImportException when the directory is not a readable directory inside the root; the message
     *                         does not tell whether a path outside the root exists
     */
    public function resolve(?string $directory): ?string
    {
        $directory = trim((string) $directory);

        if ('' === $directory) {
            return null;
        }

        $root = realpath($this->root());
        $candidate = realpath(str_starts_with($directory, '/') ? $directory : $this->root().$directory);

        if (false === $root
            || false === $candidate
            || ($candidate !== $root && !str_starts_with($candidate, $root.\DIRECTORY_SEPARATOR))
            || !is_dir($candidate)
            || !is_readable($candidate)
        ) {
            throw new ImportException('The directory %directory% is not a readable directory of %root%.', ['%directory%' => $directory, '%root%' => $this->root()]);
        }

        return $candidate;
    }
}
