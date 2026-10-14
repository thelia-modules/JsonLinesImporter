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
        /** derived from the detected MIME type, never from the source name */
        public string $extension = '',
    ) {
    }

    public function extension(): string
    {
        return $this->extension;
    }

    /**
     * Name of the source without its extension, reduced to safe characters.
     */
    public function baseName(): string
    {
        $name = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($this->originalName, \PATHINFO_FILENAME)));

        return '' === $name ? 'file' : substr($name, 0, 100);
    }

    public function safeName(): string
    {
        return $this->baseName().'.'.$this->extension;
    }

    public function discard(): void
    {
        if (is_file($this->temporaryPath)) {
            @unlink($this->temporaryPath);
        }
    }
}
