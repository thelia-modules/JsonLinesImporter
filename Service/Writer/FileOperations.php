<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service\Writer;

/**
 * Files touched while a line is written, kept apart from the database transaction.
 *
 * A file put in place is removed if the line is rolled back. A file to delete is only removed once the
 * line is committed: Thelia deletes the file before the row, which would lose it on a rollback.
 */
final class FileOperations
{
    /** @var list<string> */
    private array $placed = [];

    /** @var list<string> */
    private array $toDelete = [];

    private bool $productImagesDeleted = false;

    public function place(string $temporaryPath, string $targetPath): void
    {
        $directory = \dirname($targetPath);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('The directory %s cannot be created.', $directory));
        }

        if (!copy($temporaryPath, $targetPath)) {
            throw new \RuntimeException(\sprintf('The file %s cannot be written.', basename($targetPath)));
        }

        @chmod($targetPath, 0o664);

        $this->placed[] = $targetPath;
    }

    public function deleteAfterCommit(string $path, bool $isProductImage = false): void
    {
        $this->toDelete[] = $path;
        $this->productImagesDeleted = $this->productImagesDeleted || $isProductImage;
    }

    public function commit(): void
    {
        foreach ($this->toDelete as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->placed = [];
        $this->toDelete = [];
    }

    public function rollback(): void
    {
        foreach ($this->placed as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->placed = [];
        $this->toDelete = [];
        $this->productImagesDeleted = false;
    }

    public function haveProductImagesBeenDeleted(): bool
    {
        return $this->productImagesDeleted;
    }
}
