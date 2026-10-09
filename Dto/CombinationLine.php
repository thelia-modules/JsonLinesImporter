<?php

declare(strict_types=1);

namespace JsonLinesImporter\Dto;

final readonly class CombinationLine
{
    public function __construct(
        public Label $attribute,
        public Label $value,
    ) {
    }
}
