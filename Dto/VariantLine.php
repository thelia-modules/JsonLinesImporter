<?php

declare(strict_types=1);

namespace JsonLinesImporter\Dto;

final readonly class VariantLine
{
    /**
     * @param list<CombinationLine> $combination
     */
    public function __construct(
        public ?string $ref,
        public bool $default,
        public Field $visible,
        public SaleFields $sale,
        public array $combination,
        /** Field of list<string>|null: `_key` of images of the product */
        public Field $images,
    ) {
    }
}
