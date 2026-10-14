<?php

declare(strict_types=1);

namespace JsonLinesImporter\Dto;

/**
 * The fields of a product sale element, at the root of a product or in a variant.
 */
final readonly class SaleFields
{
    public const KEYS = ['ean_code', 'quantity', 'weight', 'newness', 'promo', 'price', 'promo_price', 'prices'];

    public function __construct(
        public Field $eanCode,
        public Field $quantity,
        public Field $weight,
        public Field $newness,
        public Field $promo,
        public Field $price,
        public Field $promoPrice,
        /** Field of list<PriceLine>|null */
        public Field $prices,
    ) {
    }

    /** @param array<string, mixed> $node */
    public static function fromNode(array $node): self
    {
        return new self(
            Field::from($node, 'ean_code', static fn (mixed $value): string => (string) $value),
            Field::from($node, 'quantity', static fn (mixed $value): float => (float) $value),
            Field::from($node, 'weight', static fn (mixed $value): float => (float) $value),
            Field::from($node, 'newness', static fn (mixed $value): bool => (bool) $value),
            Field::from($node, 'promo', static fn (mixed $value): bool => (bool) $value),
            Field::from($node, 'price', static fn (mixed $value): float => (float) $value),
            Field::from($node, 'promo_price', static fn (mixed $value): float => (float) $value),
            Field::from($node, 'prices', static fn (mixed $value): array => array_values(array_filter(
                array_map(static fn (mixed $price): ?PriceLine => \is_array($price) ? PriceLine::fromNode($price) : null, (array) $value),
            ))),
        );
    }

    /**
     * Fields absent from a variant take the value of the root of the product.
     */
    public function withFallback(self $root): self
    {
        return new self(
            $this->eanCode->present ? $this->eanCode : $root->eanCode,
            $this->quantity->present ? $this->quantity : $root->quantity,
            $this->weight->present ? $this->weight : $root->weight,
            $this->newness->present ? $this->newness : $root->newness,
            $this->promo->present ? $this->promo : $root->promo,
            $this->price->present ? $this->price : $root->price,
            $this->promoPrice->present ? $this->promoPrice : $root->promoPrice,
            $this->prices->present ? $this->prices : $root->prices,
        );
    }

    public function isEmpty(): bool
    {
        foreach ([$this->eanCode, $this->quantity, $this->weight, $this->newness, $this->promo, $this->price, $this->promoPrice, $this->prices] as $field) {
            if ($field->present) {
                return false;
            }
        }

        return true;
    }
}
