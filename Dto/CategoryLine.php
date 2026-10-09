<?php

declare(strict_types=1);

namespace JsonLinesImporter\Dto;

final readonly class CategoryLine
{
    public function __construct(
        public Label $label,
        public bool $default,
    ) {
    }
}
