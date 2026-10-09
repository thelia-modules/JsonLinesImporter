<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service\Writer;

use JsonLinesImporter\Dto\PriceLine;
use JsonLinesImporter\Dto\SaleFields;
use JsonLinesImporter\Service\LineReport;
use JsonLinesImporter\Service\ShopContext;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Model\ProductPrice;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductSaleElements;

/**
 * Prices of a sale element, one row per currency.
 *
 * `price` and `promo_price` target the default currency, unless `prices` holds an entry for it, which
 * wins. An explicit price is written with from_default_currency = 0: the column defaults to 1 and the
 * price would otherwise be ignored in favour of the exchange rate.
 */
final readonly class PriceWriter
{
    public function __construct(
        private ShopContext $shopContext,
    ) {
    }

    public function write(ProductSaleElements $saleElements, SaleFields $sale, bool $isCreation, LineReport $report, ConnectionInterface $con): void
    {
        $defaultCurrencyId = (int) $this->shopContext->defaultCurrency()->getId();

        /** @var list<PriceLine> $prices */
        $prices = $sale->prices->hasValue() ? $sale->prices->value : [];
        $defaultEntry = null;
        $otherEntries = [];

        foreach ($prices as $price) {
            $currencyId = $this->shopContext->currencyId($price->currency);

            if (null === $currencyId) {
                $report->warn(\sprintf('Price ignored: the currency %s is unknown to the shop', $price->currency));

                continue;
            }

            if ($currencyId === $defaultCurrencyId) {
                $defaultEntry = $price;

                continue;
            }

            $otherEntries[$currencyId] = $price;
        }

        $basePrice = $defaultEntry?->price ?? ($sale->price->hasValue() ? $sale->price->value : null);
        $promoPrice = null !== $defaultEntry ? $defaultEntry->promoPrice : ($sale->promoPrice->hasValue() ? $sale->promoPrice->value : null);

        $defaultRow = $this->row($saleElements, $defaultCurrencyId, $con);

        if (null !== $basePrice) {
            $defaultRow->setPrice((string) $basePrice);
        }

        if (null !== $promoPrice) {
            $defaultRow->setPromoPrice((string) $promoPrice);
        } elseif ($isCreation && null !== $basePrice) {
            $defaultRow->setPromoPrice((string) $basePrice);
        }

        if ($defaultRow->isNew() && null === $basePrice) {
            $defaultRow->setPrice('0')->setPromoPrice('0');
        }

        $defaultRow->setFromDefaultCurrency(0)->save($con);

        foreach ($otherEntries as $currencyId => $price) {
            $row = $this->row($saleElements, $currencyId, $con);

            if ($price->fromDefaultCurrency) {
                $row->setFromDefaultCurrency(1);

                if ($row->isNew()) {
                    $row->setPrice('0')->setPromoPrice('0');
                }
            } else {
                $row
                    ->setFromDefaultCurrency(0)
                    ->setPrice((string) $price->price)
                    ->setPromoPrice((string) ($price->promoPrice ?? $price->price));
            }

            $row->save($con);
        }

        // A present collection replaces the prices of the other currencies; null removes them all.
        if ($sale->prices->present) {
            ProductPriceQuery::create()
                ->filterByProductSaleElementsId($saleElements->getId())
                ->filterByCurrencyId([$defaultCurrencyId, ...array_keys($otherEntries)], Criteria::NOT_IN)
                ->delete($con);
        }
    }

    private function row(ProductSaleElements $saleElements, int $currencyId, ConnectionInterface $con): ProductPrice
    {
        return ProductPriceQuery::create()
            ->filterByProductSaleElementsId($saleElements->getId())
            ->filterByCurrencyId($currencyId)
            ->findOne($con)
            ?? (new ProductPrice())
                ->setProductSaleElementsId((int) $saleElements->getId())
                ->setCurrencyId($currencyId);
    }
}
