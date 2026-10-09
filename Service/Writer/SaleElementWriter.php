<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service\Writer;

use JsonLinesImporter\Dto\ProductLine;
use JsonLinesImporter\Dto\SaleFields;
use JsonLinesImporter\Dto\VariantLine;
use JsonLinesImporter\Enum\ReferentialType;
use JsonLinesImporter\Service\LineReport;
use JsonLinesImporter\Service\ReferentialResolver;
use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Model\AttributeCombination;
use Thelia\Model\AttributeCombinationQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsProductImage;
use Thelia\Model\ProductSaleElementsProductImageQuery;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * Sale elements of a product.
 *
 * Without `variants`, the sale fields of the root go to the default sale element. With `variants`, each
 * variant is matched with a sale element of the product by its ref: updated in place (it keeps its id),
 * or created. A sale element missing from the list is hidden, never deleted: carts hold it by cascade
 * and orders keep its id.
 */
final readonly class SaleElementWriter
{
    public function __construct(
        private PriceWriter $priceWriter,
        private ReferentialResolver $resolver,
    ) {
    }

    /**
     * @param array<string, int> $imageKeys `_key` => product image id
     */
    public function write(Product $product, ProductLine $line, bool $isCreation, array $imageKeys, LineReport $report, ConnectionInterface $con): void
    {
        if ($line->variants->isNull()) {
            $report->warn('variants: null ignored, a product keeps at least one sale element');
        }

        if (!$line->variants->hasValue() || [] === $line->variants->value) {
            if (!$line->sale->isEmpty()) {
                $saleElements = $this->defaultSaleElements($product, $con);
                $this->applyFields($saleElements, $line->sale, $isCreation, $report, $con);
            }

            return;
        }

        $this->writeVariants($product, $line, $isCreation, $imageKeys, $report, $con);
    }

    /** @param array<string, int> $imageKeys */
    private function writeVariants(Product $product, ProductLine $line, bool $isCreation, array $imageKeys, LineReport $report, ConnectionInterface $con): void
    {
        /** @var list<VariantLine> $variants */
        $variants = $line->variants->value;

        $existing = [];

        foreach (ProductSaleElementsQuery::create()->filterByProductId($product->getId())->orderById()->find($con) as $saleElements) {
            $existing[(int) $saleElements->getId()] = $saleElements;
        }

        $kept = [];
        $defaultIndex = 0;

        foreach ($variants as $index => $variant) {
            if ($variant->default) {
                $defaultIndex = $index;
                break;
            }
        }

        foreach ($variants as $index => $variant) {
            $ref = $variant->ref ?? (string) $product->getRef();
            $saleElements = $this->match($existing, $kept, $ref);
            $isNew = null === $saleElements;

            if ($isNew) {
                $saleElements = (new ProductSaleElements())
                    ->setProductId((int) $product->getId())
                    ->setQuantity(0)
                    ->setWeight(0)
                    ->setPromo(0)
                    ->setNewness(0)
                    ->setVisible(true);
            }

            $saleElements
                ->setRef($ref)
                ->setIsDefault($index === $defaultIndex ? 1 : 0);

            if ($variant->visible->present || $isNew) {
                $saleElements->setVisible((bool) $variant->visible->or(true));
            } elseif (!$saleElements->getVisible()) {
                // Back in the file: shown again.
                $saleElements->setVisible(true);
            }

            $this->applyFields($saleElements, $variant->sale->withFallback($line->sale), $isCreation || $isNew, $report, $con);

            $kept[(int) $saleElements->getId()] = true;

            $this->writeCombination($saleElements, $variant, $index, $report, $con);
            $this->writeImages($saleElements, $variant, $line, $imageKeys, $index, $report, $con);
        }

        foreach ($existing as $id => $saleElements) {
            if (!isset($kept[$id])) {
                $saleElements->setVisible(false)->setIsDefault(0)->save($con);
            }
        }
    }

    /**
     * @param array<int, ProductSaleElements> $existing
     * @param array<int, true>               $kept
     */
    private function match(array $existing, array $kept, string $ref): ?ProductSaleElements
    {
        foreach ($existing as $id => $saleElements) {
            if (!isset($kept[$id]) && $saleElements->getRef() === $ref) {
                return $saleElements;
            }
        }

        // The single sale element of a product created without variants takes the first variant.
        if ([] === $kept) {
            foreach ($existing as $id => $saleElements) {
                if (0 === AttributeCombinationQuery::create()->filterByProductSaleElementsId($id)->count()) {
                    return $saleElements;
                }
            }
        }

        return null;
    }

    private function applyFields(ProductSaleElements $saleElements, SaleFields $sale, bool $isCreation, LineReport $report, ConnectionInterface $con): void
    {
        if ($sale->eanCode->present) {
            $saleElements->setEanCode($sale->eanCode->value);
        }

        if ($sale->quantity->present) {
            $saleElements->setQuantity((float) $sale->quantity->or(0));
        }

        if ($sale->weight->present) {
            $saleElements->setWeight((float) $sale->weight->or(0));
        }

        if ($sale->newness->present) {
            $saleElements->setNewness($sale->newness->or(false) ? 1 : 0);
        }

        if ($sale->promo->present) {
            $saleElements->setPromo($sale->promo->or(false) ? 1 : 0);
        }

        $saleElements->save($con);

        if ($isCreation || $sale->price->present || $sale->promoPrice->present || $sale->prices->present) {
            $this->priceWriter->write($saleElements, $sale, $isCreation, $report, $con);
        }
    }

    private function writeCombination(ProductSaleElements $saleElements, VariantLine $variant, int $index, LineReport $report, ConnectionInterface $con): void
    {
        $pairs = [];

        foreach ($variant->combination as $item) {
            $attributeId = $this->resolver->resolve(ReferentialType::ATTRIBUTE, $item->attribute);

            if (null === $attributeId) {
                $report->warn(\sprintf('variants[%d]: attribute "%s" ignored', $index, $item->attribute->matchingTitle));

                continue;
            }

            $valueId = $this->resolver->resolve(ReferentialType::ATTRIBUTE_VALUE, $item->value, $attributeId);

            if (null === $valueId) {
                $report->warn(\sprintf('variants[%d]: value "%s" of attribute "%s" ignored', $index, $item->value->matchingTitle, $item->attribute->matchingTitle));

                continue;
            }

            $pairs[$attributeId] = $valueId;
        }

        $current = [];

        foreach (AttributeCombinationQuery::create()->filterByProductSaleElementsId($saleElements->getId())->find($con) as $combination) {
            $current[(int) $combination->getAttributeId()] = (int) $combination->getAttributeAvId();
        }

        if ($current === $pairs) {
            return;
        }

        AttributeCombinationQuery::create()->filterByProductSaleElementsId($saleElements->getId())->delete($con);

        foreach ($pairs as $attributeId => $valueId) {
            (new AttributeCombination())
                ->setProductSaleElementsId((int) $saleElements->getId())
                ->setAttributeId($attributeId)
                ->setAttributeAvId($valueId)
                ->save($con);
        }
    }

    /** @param array<string, int> $imageKeys */
    private function writeImages(ProductSaleElements $saleElements, VariantLine $variant, ProductLine $line, array $imageKeys, int $index, LineReport $report, ConnectionInterface $con): void
    {
        if (!$variant->images->present) {
            return;
        }

        if ($variant->images->hasValue() && !$line->images->hasValue()) {
            $report->warn(\sprintf('variants[%d].images ignored: the images of the product are not imported', $index));

            return;
        }

        ProductSaleElementsProductImageQuery::create()->filterByProductSaleElementsId($saleElements->getId())->delete($con);

        foreach ($variant->images->value ?? [] as $key) {
            if (!isset($imageKeys[$key])) {
                $report->warn(\sprintf('variants[%d].images: the image %s could not be imported', $index, $key));

                continue;
            }

            (new ProductSaleElementsProductImage())
                ->setProductSaleElementsId((int) $saleElements->getId())
                ->setProductImageId($imageKeys[$key])
                ->save($con);
        }
    }

    private function defaultSaleElements(Product $product, ConnectionInterface $con): ProductSaleElements
    {
        return ProductSaleElementsQuery::create()->filterByProductId($product->getId())->filterByIsDefault(1)->findOne($con)
            ?? ProductSaleElementsQuery::create()->filterByProductId($product->getId())->orderById()->findOne($con)
            ?? (new ProductSaleElements())
                ->setProductId((int) $product->getId())
                ->setRef((string) $product->getRef())
                ->setIsDefault(1)
                ->setQuantity(0)
                ->setVisible(true);
    }
}
