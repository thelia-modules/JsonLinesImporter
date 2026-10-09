<?php

declare(strict_types=1);

namespace JsonLinesImporter\Dto;

/**
 * An image or a document of the product.
 */
final readonly class MediaLine
{
    public const TEXT_FIELDS = ['title', 'chapo', 'description', 'postscriptum'];

    public function __construct(
        public ?string $key,
        public string $source,
        public Field $visible,
        public ?int $position,
        public I18nBag $i18n,
    ) {
    }
}
