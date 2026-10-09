<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Unit;

use JsonLinesImporter\Dto\PriceLine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PriceLineTest extends TestCase
{
    #[Test]
    public function anExplicitPriceWinsOverTheDefaultCurrency(): void
    {
        $price = PriceLine::fromNode(['currency' => 'USD', 'price' => 99, 'from_default_currency' => true]);

        self::assertNotNull($price);
        self::assertFalse($price->fromDefaultCurrency);
        self::assertSame(99.0, $price->price);
    }

    #[Test]
    public function withoutPriceThePriceComesFromTheDefaultCurrencyAndThePromoPriceIsIgnored(): void
    {
        $price = PriceLine::fromNode(['currency' => 'USD', 'promo_price' => 5]);

        self::assertNotNull($price);
        self::assertTrue($price->fromDefaultCurrency);
        self::assertNull($price->promoPrice);
    }

    #[Test]
    public function anEntryWithoutPriceNorDefaultCurrencyIsIgnored(): void
    {
        self::assertNull(PriceLine::fromNode(['currency' => 'USD', 'from_default_currency' => false]));
        self::assertNull(PriceLine::fromNode(['currency' => 'usd', 'price' => 1]));
    }
}
