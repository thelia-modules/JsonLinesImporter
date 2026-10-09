<?php

declare(strict_types=1);

namespace JsonLinesImporter\Dto;

final readonly class AccessoryLine
{
    public function __construct(
        public string $ref,
        public ?int $position,
    ) {
    }
}
