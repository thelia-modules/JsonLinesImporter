<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service;

use JsonLinesImporter\Dto\Label;
use JsonLinesImporter\Enum\ReferentialType;
use JsonLinesImporter\Model\JsonlImportMapping;
use JsonLinesImporter\Model\JsonlImportMappingQuery;
use Propel\Runtime\ActiveRecord\ActiveRecordInterface;
use Thelia\Model\Attribute;
use Thelia\Model\AttributeAv;
use Thelia\Model\AttributeAvI18nQuery;
use Thelia\Model\AttributeI18nQuery;
use Thelia\Model\Brand;
use Thelia\Model\BrandI18nQuery;
use Thelia\Model\Category;
use Thelia\Model\CategoryI18nQuery;
use Thelia\Model\Feature;
use Thelia\Model\FeatureAv;
use Thelia\Model\FeatureAvI18nQuery;
use Thelia\Model\FeatureI18nQuery;
use Thelia\Model\TaxRuleI18nQuery;

/**
 * Finds the shop element a title of the file stands for.
 *
 * The memorized choice of a previous import comes first, then the element with the same title in the
 * default locale of the shop (case and surrounding spaces ignored). A value of a feature or an attribute
 * is looked for under the element its parent resolved to.
 */
final class ReferentialResolver
{
    /** @var array<string, ?int> */
    private array $cache = [];

    public function __construct(
        private readonly ShopContext $shopContext,
    ) {
    }

    /**
     * @return int|null the Thelia id, null when the title is ignored or unknown
     */
    public function resolve(ReferentialType $type, Label $label, int $parentId = 0): ?int
    {
        $title = $label->normalizedTitle();

        if ('' === $title) {
            return null;
        }

        $cacheKey = $type->value.'|'.$parentId.'|'.$title;

        if (\array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $mapping = $this->findMapping($type, $parentId, $title);

        if (null !== $mapping) {
            return $this->cache[$cacheKey] = $mapping->getIgnored() ? null : $this->existingTarget($type, $mapping->getTargetId());
        }

        return $this->cache[$cacheKey] = $this->findByTitle($type, $label->matchingTitle, $parentId);
    }

    public function findMapping(ReferentialType $type, int $parentId, string $normalizedTitle): ?JsonlImportMapping
    {
        return JsonlImportMappingQuery::create()
            ->filterByType($type->value)
            ->filterByParentId($parentId)
            ->filterBySourceTitle($normalizedTitle)
            ->findOne();
    }

    public function memorize(ReferentialType $type, int $parentId, string $normalizedTitle, ?int $targetId, bool $ignored): void
    {
        $mapping = $this->findMapping($type, $parentId, $normalizedTitle) ?? (new JsonlImportMapping())
            ->setType($type->value)
            ->setParentId($parentId)
            ->setSourceTitle($normalizedTitle);

        $mapping
            ->setTargetId($ignored ? null : $targetId)
            ->setIgnored($ignored ? 1 : 0)
            ->save();

        $this->cache = [];
    }

    public function findByTitle(ReferentialType $type, string $title, int $parentId = 0): ?int
    {
        $locale = $this->shopContext->defaultLocale();
        $title = trim($title);

        $query = match ($type) {
            ReferentialType::TAX_RULE => TaxRuleI18nQuery::create(),
            ReferentialType::CATEGORY => CategoryI18nQuery::create(),
            ReferentialType::BRAND => BrandI18nQuery::create(),
            ReferentialType::FEATURE => FeatureI18nQuery::create(),
            ReferentialType::ATTRIBUTE => AttributeI18nQuery::create(),
            ReferentialType::FEATURE_VALUE => FeatureAvI18nQuery::create()
                ->useFeatureAvQuery()->filterByFeatureId($parentId)->endUse(),
            ReferentialType::ATTRIBUTE_VALUE => AttributeAvI18nQuery::create()
                ->useAttributeAvQuery()->filterByAttributeId($parentId)->endUse(),
        };

        $row = $query->filterByLocale($locale)->filterByTitle($title)->findOne();

        return null === $row ? null : (int) $row->getId();
    }

    /**
     * Creates the element, hidden, with the titles of the file in each of their locales.
     */
    public function create(ReferentialType $type, Label $label, int $parentId = 0): int
    {
        $element = match ($type) {
            ReferentialType::CATEGORY => (new Category())->setParent($parentId)->setVisible(0),
            ReferentialType::BRAND => (new Brand())->setVisible(0),
            ReferentialType::FEATURE => (new Feature())->setVisible(0),
            ReferentialType::FEATURE_VALUE => (new FeatureAv())->setFeatureId($parentId),
            ReferentialType::ATTRIBUTE => new Attribute(),
            ReferentialType::ATTRIBUTE_VALUE => (new AttributeAv())->setAttributeId($parentId),
            ReferentialType::TAX_RULE => throw new \LogicException('A tax rule cannot be created by an import.'),
        };

        $titles = $label->titles->values;

        if ([] === $titles) {
            $titles = [$this->shopContext->defaultLocale() => ['title' => $label->matchingTitle]];
        }

        foreach ($titles as $locale => $fields) {
            $element->setLocale($locale)->setTitle((string) ($fields['title'] ?? $label->matchingTitle));
        }

        $element->save();

        $this->cache = [];

        return (int) $element->getId();
    }

    private function existingTarget(ReferentialType $type, ?int $targetId): ?int
    {
        if (null === $targetId) {
            return null;
        }

        $queryClass = match ($type) {
            ReferentialType::TAX_RULE => \Thelia\Model\TaxRuleQuery::class,
            ReferentialType::CATEGORY => \Thelia\Model\CategoryQuery::class,
            ReferentialType::BRAND => \Thelia\Model\BrandQuery::class,
            ReferentialType::FEATURE => \Thelia\Model\FeatureQuery::class,
            ReferentialType::FEATURE_VALUE => \Thelia\Model\FeatureAvQuery::class,
            ReferentialType::ATTRIBUTE => \Thelia\Model\AttributeQuery::class,
            ReferentialType::ATTRIBUTE_VALUE => \Thelia\Model\AttributeAvQuery::class,
        };

        /** @var ActiveRecordInterface|null $element */
        $element = $queryClass::create()->findPk($targetId);

        return null === $element ? null : $targetId;
    }
}
