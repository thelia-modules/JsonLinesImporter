<?php

declare(strict_types=1);

namespace JsonLinesImporter\Dto;

final readonly class VideoLine
{
    public function __construct(
        public string $source,
        public ?int $position,
        /** fields title and group */
        public I18nBag $i18n,
    ) {
    }
}
