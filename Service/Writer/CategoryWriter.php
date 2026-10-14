<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service\Writer;

use JsonLinesImporter\Dto\CategoryLine;
use JsonLinesImporter\Dto\ProductLine;
use JsonLinesImporter\Enum\ReferentialType;
use JsonLinesImporter\Service\LineReport;
use JsonLinesImporter\Service\ReferentialResolver;
use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Model\Product;
use Thelia\Model\ProductCategory;
use Thelia\Model\ProductCategoryQuery;

/**
 * Categories of a product, after mapping.
 *
 * Product::setDefaultCategory() deletes the former default link instead of demoting it: the links are
 * computed and written here. A product never ends without category: when every category of the line is
 * ignored, an existing product keeps its own.
 */
final readonly class CategoryWriter
{
    public function __construct(
        private ReferentialResolver $resolver,
    ) {
    }

    /**
     * @return list<int> the category ids of the line, the default one first (empty when none resolves)
     */
    public function resolve(ProductLine $line, LineReport $report): array
    {
        if (!$line->categories->hasValue()) {
            return [];
        }

        $ids = [];
        $defaultId = null;

        /** @var CategoryLine $category */
        foreach ($line->categories->value as $category) {
            $id = $this->resolver->resolve(ReferentialType::CATEGORY, $category->label);

            if (null === $id) {
                $report->warn(\sprintf('Category "%s" ignored', $category->label->matchingTitle));

                continue;
            }

            $ids[$id] = true;

            if ($category->default && null === $defaultId) {
                $defaultId = $id;
            }
        }

        $ids = array_keys($ids);

        if (null !== $defaultId) {
            $ids = [$defaultId, ...array_values(array_diff($ids, [$defaultId]))];
        }

        return $ids;
    }

    public function write(Product $product, ProductLine $line, LineReport $report, ConnectionInterface $con): void
    {
        if ($line->categories->isNull()) {
            $report->warn('categories: null ignored, a product keeps at least one category');

            return;
        }

        if (!$line->categories->hasValue()) {
            return;
        }

        $targetIds = $this->resolve($line, $report);

        if ([] === $targetIds) {
            $report->warn('No category of the line could be resolved: the categories of the product are left unchanged');

            return;
        }

        $defaultId = $targetIds[0];
        $links = [];

        foreach (ProductCategoryQuery::create()->filterByProductId($product->getId())->find($con) as $link) {
            if (!\in_array((int) $link->getCategoryId(), $targetIds, true)) {
                $link->delete($con);

                continue;
            }

            $links[(int) $link->getCategoryId()] = $link;
        }

        foreach ($targetIds as $categoryId) {
            $link = $links[$categoryId] ?? null;

            if (null === $link) {
                $link = (new ProductCategory())
                    ->setProductId((int) $product->getId())
                    ->setCategoryId($categoryId);
                $link->setPosition($link->getNextPosition());
            }

            $link->setDefaultCategory($categoryId === $defaultId ? 1 : 0)->save($con);
        }
    }
}
