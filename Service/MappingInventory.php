<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service;

use JsonLinesImporter\Dto\ImportParameters;
use JsonLinesImporter\Dto\Label;
use JsonLinesImporter\Enum\ReferentialType;
use JsonLinesImporter\Model\JsonlImport;
use JsonLinesImporter\Model\JsonlImportLineQuery;
use Thelia\Model\AttributeAvQuery;
use Thelia\Model\AttributeQuery;
use Thelia\Model\BrandQuery;
use Thelia\Model\CategoryQuery;
use Thelia\Model\FeatureAvQuery;
use Thelia\Model\FeatureQuery;
use Thelia\Model\TaxRuleQuery;

/**
 * Step 3: the distinct titles of a referential in the valid lines, with the element each one is
 * proposed to stand for, and the elements of the shop to choose from.
 */
final readonly class MappingInventory
{
    public const CHOICE_IGNORE = 'ignore';

    public const CHOICE_CREATE = 'create';

    public function __construct(
        private ReferentialResolver $resolver,
        private ShopContext $shopContext,
    ) {
    }

    /**
     * Mapping pages to show, in the order of the wizard: a referential with no title in the selected
     * data has no page.
     *
     * @return list<ReferentialType>
     */
    public function pages(JsonlImport $import): array
    {
        $parameters = ImportParameters::fromArray($import->getParametersArray());
        $pages = [];

        foreach (ReferentialType::pages() as $type) {
            if ($parameters->isKeySelected(self::keyOf($type)) && [] !== $this->entries($import, $type)) {
                $pages[] = $type;
            }
        }

        return $pages;
    }

    public static function keyOf(ReferentialType $type): string
    {
        return match ($type) {
            ReferentialType::TAX_RULE => 'tax_rule',
            ReferentialType::CATEGORY => 'categories',
            ReferentialType::BRAND => 'brand',
            ReferentialType::FEATURE, ReferentialType::FEATURE_VALUE => 'features',
            ReferentialType::ATTRIBUTE, ReferentialType::ATTRIBUTE_VALUE => 'variants',
        };
    }

    /**
     * @return list<array{key: string, title: string, normalized: string, count: int, parent_id: int, parent_title: ?string, label: Label, choice: string, origin: string}>
     */
    public function entries(JsonlImport $import, ReferentialType $type): array
    {
        $defaultLocale = $this->shopContext->defaultLocale();
        $entries = [];

        $payloads = JsonlImportLineQuery::create()
            ->filterByImportId($import->getId())
            ->filterByValid(1)
            ->select(['Payload'])
            ->find();

        foreach ($payloads as $payload) {
            $line = json_decode((string) $payload, true);

            if (!\is_array($line)) {
                continue;
            }

            foreach ($this->labelsOf($line, $type, $defaultLocale) as [$label, $parentId, $parentTitle]) {
                $normalized = $label->normalizedTitle();

                if ('' === $normalized) {
                    continue;
                }

                $key = $parentId.'|'.$normalized;
                $entries[$key] ??= [
                    'key' => md5($key),
                    'title' => $label->matchingTitle,
                    'normalized' => $normalized,
                    'count' => 0,
                    'parent_id' => $parentId,
                    'parent_title' => $parentTitle,
                    'label' => $label,
                ];
                ++$entries[$key]['count'];
            }
        }

        foreach ($entries as &$entry) {
            [$entry['choice'], $entry['origin']] = $this->currentChoice($type, $entry['parent_id'], $entry['normalized'], $entry['title']);
        }
        unset($entry);

        uasort($entries, static fn (array $left, array $right): int => [$left['parent_title'], $left['title']] <=> [$right['parent_title'], $right['title']]);

        return array_values($entries);
    }

    /**
     * @return array<int, string> id => title of the shop elements a title can be mapped to
     */
    public function options(ReferentialType $type, int $parentId = 0): array
    {
        $locale = $this->shopContext->defaultLocale();

        $query = match ($type) {
            ReferentialType::TAX_RULE => TaxRuleQuery::create(),
            ReferentialType::CATEGORY => CategoryQuery::create(),
            ReferentialType::BRAND => BrandQuery::create(),
            ReferentialType::FEATURE => FeatureQuery::create(),
            ReferentialType::ATTRIBUTE => AttributeQuery::create(),
            ReferentialType::FEATURE_VALUE => FeatureAvQuery::create()->filterByFeatureId($parentId),
            ReferentialType::ATTRIBUTE_VALUE => AttributeAvQuery::create()->filterByAttributeId($parentId),
        };

        $options = [];

        foreach ($query->find() as $element) {
            $title = (string) $element->setLocale($locale)->getTitle();
            $options[(int) $element->getId()] = '' === $title ? '#'.$element->getId() : $title;
        }

        asort($options, \SORT_NATURAL | \SORT_FLAG_CASE);

        return $options;
    }

    /**
     * Applies the choice made for a title: an existing element, a new one (created now), or ignored.
     */
    public function apply(ReferentialType $type, array $entry, string $choice, int $categoryParentId = 0): void
    {
        if (self::CHOICE_IGNORE === $choice || '' === $choice) {
            $this->resolver->memorize($type, $entry['parent_id'], $entry['normalized'], null, true);

            return;
        }

        if (self::CHOICE_CREATE === $choice && $type->canBeCreated()) {
            $parentId = ReferentialType::CATEGORY === $type ? $categoryParentId : $entry['parent_id'];
            $targetId = $this->resolver->create($type, $entry['label'], $parentId);
            $this->resolver->memorize($type, $entry['parent_id'], $entry['normalized'], $targetId, false);

            return;
        }

        if (ctype_digit($choice)) {
            $this->resolver->memorize($type, $entry['parent_id'], $entry['normalized'], (int) $choice, false);
        }
    }

    /**
     * @return array{string, string} the choice ("<id>" or "ignore") and where it comes from (memorized, title, none)
     */
    private function currentChoice(ReferentialType $type, int $parentId, string $normalized, string $title): array
    {
        $mapping = $this->resolver->findMapping($type, $parentId, $normalized);

        if (null !== $mapping) {
            return [$mapping->getIgnored() || null === $mapping->getTargetId() ? self::CHOICE_IGNORE : (string) $mapping->getTargetId(), 'memorized'];
        }

        $id = $this->resolver->findByTitle($type, $title, $parentId);

        return null !== $id ? [(string) $id, 'title'] : [$type->canBeCreated() ? self::CHOICE_CREATE : self::CHOICE_IGNORE, 'none'];
    }

    /**
     * @return list<array{Label, int, ?string}> label, Thelia id of its parent (0 when none), title of the parent
     */
    private function labelsOf(array $line, ReferentialType $type, string $defaultLocale): array
    {
        $labels = [];

        switch ($type) {
            case ReferentialType::TAX_RULE:
            case ReferentialType::BRAND:
                $node = $line[$type->value] ?? null;

                if (\is_array($node)) {
                    $labels[] = [Label::fromNode($node, $defaultLocale), 0, null];
                }

                break;
            case ReferentialType::CATEGORY:
                foreach ((array) ($line['categories'] ?? []) as $node) {
                    $labels[] = [Label::fromNode($node, $defaultLocale), 0, null];
                }

                break;
            case ReferentialType::FEATURE:
            case ReferentialType::FEATURE_VALUE:
                foreach ((array) ($line['features'] ?? []) as $node) {
                    $feature = Label::fromNode($node['feature'], $defaultLocale);

                    if (ReferentialType::FEATURE === $type) {
                        $labels[] = [$feature, 0, null];

                        continue;
                    }

                    $featureId = ($node['value']['free'] ?? false) ? null : $this->resolver->resolve(ReferentialType::FEATURE, $feature);

                    if (null !== $featureId) {
                        $labels[] = [Label::fromNode($node['value'], $defaultLocale), $featureId, $feature->matchingTitle];
                    }
                }

                break;
            case ReferentialType::ATTRIBUTE:
            case ReferentialType::ATTRIBUTE_VALUE:
                foreach ((array) ($line['variants'] ?? []) as $variant) {
                    foreach ((array) ($variant['combination'] ?? []) as $item) {
                        $attribute = Label::fromNode($item['attribute'], $defaultLocale);

                        if (ReferentialType::ATTRIBUTE === $type) {
                            $labels[] = [$attribute, 0, null];

                            continue;
                        }

                        $attributeId = $this->resolver->resolve(ReferentialType::ATTRIBUTE, $attribute);

                        if (null !== $attributeId) {
                            $labels[] = [Label::fromNode($item['value'], $defaultLocale), $attributeId, $attribute->matchingTitle];
                        }
                    }
                }

                break;
        }

        return $labels;
    }
}
