<?php

declare(strict_types=1);

namespace JsonLinesImporter\Reader;

final readonly class RawLine
{
    public function __construct(
        public int $number,
        public string $content,
        public int $nextOffset,
    ) {
    }

    public function isBlank(): bool
    {
        return '' === trim($this->content);
    }
}
