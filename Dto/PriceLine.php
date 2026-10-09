<?php

declare(strict_types=1);

namespace JsonLinesImporter\Dto;

final readonly class PriceLine
{
    public function __construct(
        public string $currency,
        public ?float $price,
        public ?float $promoPrice,
        public bool $fromDefaultCurrency,
    ) {
    }

    /**
     * Rules of the structure document: an explicit price wins over from_default_currency, which
     * defaults to true; a promo price without price is ignored when the price is computed.
     *
     * @param array<string, mixed> $node
     */
    public static function fromNode(array $node): ?self
    {
        $currency = $node['currency'] ?? null;

        if (!\is_string($currency) || 1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            return null;
        }

        $price = isset($node['price']) ? (float) $node['price'] : null;

        if (null !== $price) {
            return new self($currency, $price, isset($node['promo_price']) ? (float) $node['promo_price'] : null, false);
        }

        if (false === ($node['from_default_currency'] ?? true)) {
            return null;
        }

        return new self($currency, null, null, true);
    }
}
