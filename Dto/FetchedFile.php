<?php

declare(strict_types=1);

namespace JsonLinesImporter\Dto;

/**
 * A media file copied to a temporary path, ready to be moved where Thelia keeps it.
 */
final readonly class FetchedFile
{
    public function __construct(
        public string $temporaryPath,
        public string $originalName,
        public string $mimeType,
    ) {
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->originalName, \PATHINFO_EXTENSION));
    }

    public function discard(): void
    {
        if (is_file($this->temporaryPath)) {
            @unlink($this->temporaryPath);
        }
    }
}
