<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service;

/**
 * Warnings gathered while a line is written. A warning never stops the line.
 */
final class LineReport
{
    /** @var list<string> */
    private array $warnings = [];

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    private bool $productImagesDeleted = false;

    public function markProductImagesDeleted(): void
    {
        $this->productImagesDeleted = true;
    }

    public function hasDeletedProductImages(): bool
    {
        return $this->productImagesDeleted;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }
}
