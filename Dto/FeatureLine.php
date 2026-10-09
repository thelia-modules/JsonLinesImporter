<?php

declare(strict_types=1);

namespace JsonLinesImporter\Dto;

final readonly class FeatureLine
{
    public function __construct(
        public Label $feature,
        public Label $value,
        public bool $free,
    ) {
    }
}
