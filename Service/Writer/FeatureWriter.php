<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service\Writer;

use JsonLinesImporter\Dto\FeatureLine;
use JsonLinesImporter\Dto\I18nBag;
use JsonLinesImporter\Dto\ProductLine;
use JsonLinesImporter\Enum\ReferentialType;
use JsonLinesImporter\Service\LineReport;
use JsonLinesImporter\Service\ReferentialResolver;
use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Model\FeatureAv;
use Thelia\Model\FeatureAvQuery;
use Thelia\Model\FeatureProduct;
use Thelia\Model\FeatureProductQuery;
use Thelia\Model\Product;

/**
 * Features of a product, matched on the (feature, value) pair: a feature may carry several values.
 *
 * A free value is a feature value of its own, translatable, the way the back-office creates it; the
 * deprecated free_text_value column is not used.
 */
final readonly class FeatureWriter
{
    public function __construct(
        private ReferentialResolver $resolver,
    ) {
    }

    public function write(Product $product, ProductLine $line, LineReport $report, ConnectionInterface $con): void
    {
        if (!$line->features->present) {
            return;
        }

        $existing = FeatureProductQuery::create()->filterByProductId($product->getId())->find($con)->getData();
        $kept = [];

        /** @var FeatureLine $feature */
        foreach ($line->features->value ?? [] as $feature) {
            $featureId = $this->resolver->resolve(ReferentialType::FEATURE, $feature->feature);

            if (null === $featureId) {
                $report->warn(\sprintf('Feature "%s" ignored', $feature->feature->matchingTitle));

                continue;
            }

            if ($feature->free) {
                $link = $this->takeFreeLink($existing, $kept, $featureId) ?? $this->createFreeLink($product, $featureId, $con);
                $this->writeFreeValue((int) $link->getFeatureAvId(), $feature->value->titles, $con);
                $kept[(int) $link->getId()] = true;

                continue;
            }

            $valueId = $this->resolver->resolve(ReferentialType::FEATURE_VALUE, $feature->value, $featureId);

            if (null === $valueId) {
                $report->warn(\sprintf('Value "%s" of feature "%s" ignored', $feature->value->matchingTitle, $feature->feature->matchingTitle));

                continue;
            }

            $link = null;

            foreach ($existing as $candidate) {
                if (!$candidate->getIsFreeText() && (int) $candidate->getFeatureId() === $featureId && (int) $candidate->getFeatureAvId() === $valueId) {
                    $link = $candidate;
                    break;
                }
            }

            $link ??= (new FeatureProduct())
                ->setProductId((int) $product->getId())
                ->setFeatureId($featureId)
                ->setFeatureAvId($valueId)
                ->setIsFreeText(0);
            $link->save($con);

            $kept[(int) $link->getId()] = true;
        }

        foreach ($existing as $link) {
            if (isset($kept[(int) $link->getId()])) {
                continue;
            }

            $freeValueId = $link->getIsFreeText() ? $link->getFeatureAvId() : null;
            $link->delete($con);

            if (null !== $freeValueId) {
                FeatureAvQuery::create()->filterById($freeValueId)->delete($con);
            }
        }
    }

    /**
     * Writes the free value of a feature, one title per locale.
     */
    private function writeFreeValue(int $featureAvId, I18nBag $titles, ConnectionInterface $con): void
    {
        $value = FeatureAvQuery::create()->findPk($featureAvId, $con);

        if (null === $value) {
            return;
        }

        foreach ($titles->values as $locale => $fields) {
            if (\array_key_exists('title', $fields)) {
                $value->setLocale($locale)->setTitle($fields['title']);
            }
        }

        $value->save($con);
    }

    private function createFreeLink(Product $product, int $featureId, ConnectionInterface $con): FeatureProduct
    {
        $value = (new FeatureAv())->setFeatureId($featureId);
        $value->save($con);

        $link = (new FeatureProduct())
            ->setProductId((int) $product->getId())
            ->setFeatureId($featureId)
            ->setFeatureAvId((int) $value->getId())
            ->setIsFreeText(1);
        $link->save($con);

        return $link;
    }

    /**
     * @param list<FeatureProduct> $existing
     * @param array<int, true>     $kept
     */
    private function takeFreeLink(array $existing, array $kept, int $featureId): ?FeatureProduct
    {
        foreach ($existing as $link) {
            if ($link->getIsFreeText() && (int) $link->getFeatureId() === $featureId && null !== $link->getFeatureAvId() && !isset($kept[(int) $link->getId()])) {
                return $link;
            }
        }

        return null;
    }
}
