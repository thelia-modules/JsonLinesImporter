<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service;

use JsonLinesImporter\Dto\AccessoryLine;
use JsonLinesImporter\Dto\CategoryLine;
use JsonLinesImporter\Dto\CombinationLine;
use JsonLinesImporter\Dto\FeatureLine;
use JsonLinesImporter\Dto\Field;
use JsonLinesImporter\Dto\I18nBag;
use JsonLinesImporter\Dto\ImportParameters;
use JsonLinesImporter\Dto\Label;
use JsonLinesImporter\Dto\MediaLine;
use JsonLinesImporter\Dto\ProductLine;
use JsonLinesImporter\Dto\SaleFields;
use JsonLinesImporter\Dto\VariantLine;
use JsonLinesImporter\Dto\VideoLine;

/**
 * Builds the typed view of a line, restricted to the keys and locales selected at step 2.
 *
 * An unselected key is turned into an absent field: the shop keeps its value. The product title is the
 * exception: it is always kept, because a product is created with it whatever the selection.
 */
final readonly class ProductLineFactory
{
    /** @param array<string, mixed> $data a decoded line, valid against the schema */
    public function create(array $data, string $defaultLocale, ImportParameters $parameters): ProductLine
    {
        $selected = static fn (string $key): bool => $parameters->isKeySelected($key);
        $locales = $parameters->selectedLocales;
        $keep = static fn (string $key): array => $selected($key) ? $data : [];

        $textFields = array_values(array_filter(
            ProductLine::TEXT_FIELDS,
            static fn (string $field): bool => 'title' === $field || $selected($field),
        ));

        $saleNode = array_filter($data, static fn (string $key): bool => \in_array($key, SaleFields::KEYS, true) && $selected($key), \ARRAY_FILTER_USE_KEY);

        return new ProductLine(
            ref: trim((string) $data['ref']),
            visible: Field::from($keep('visible'), 'visible', static fn (mixed $value): bool => (bool) $value),
            virtual: Field::from($keep('virtual'), 'virtual', static fn (mixed $value): bool => (bool) $value),
            position: Field::from($keep('position'), 'position', static fn (mixed $value): int => (int) $value),
            i18n: I18nBag::fromNode($data, $textFields, $defaultLocale, $locales),
            titleSelected: $selected('title'),
            urls: $selected('url') ? I18nBag::fromNode($data, ['url'], $defaultLocale, $locales) : new I18nBag(),
            sale: SaleFields::fromNode($saleNode),
            taxRule: Field::from($keep('tax_rule'), 'tax_rule', static fn (array $node): Label => Label::fromNode($node, $defaultLocale)),
            categories: Field::from($keep('categories'), 'categories', static fn (array $nodes): array => array_map(
                static fn (array $node): CategoryLine => new CategoryLine(Label::fromNode($node, $defaultLocale), (bool) ($node['default'] ?? false)),
                array_values($nodes),
            )),
            brand: Field::from($keep('brand'), 'brand', static fn (array $node): Label => Label::fromNode($node, $defaultLocale)),
            features: Field::from($keep('features'), 'features', static fn (array $nodes): array => array_map(
                static fn (array $node): FeatureLine => new FeatureLine(
                    Label::fromNode($node['feature'], $defaultLocale),
                    Label::fromNode(($node['value']['free'] ?? false) ? self::filterLocales($node['value'], $locales) : $node['value'], $defaultLocale),
                    (bool) ($node['value']['free'] ?? false),
                ),
                array_values($nodes),
            )),
            variants: Field::from($keep('variants'), 'variants', static fn (array $nodes): array => array_map(
                static fn (array $node): VariantLine => new VariantLine(
                    ref: isset($node['ref']) ? trim((string) $node['ref']) : null,
                    default: (bool) ($node['default'] ?? false),
                    visible: Field::from($node, 'visible', static fn (mixed $value): bool => (bool) $value),
                    sale: SaleFields::fromNode($node),
                    combination: array_map(
                        static fn (array $item): CombinationLine => new CombinationLine(
                            Label::fromNode($item['attribute'], $defaultLocale),
                            Label::fromNode($item['value'], $defaultLocale),
                        ),
                        array_values((array) ($node['combination'] ?? [])),
                    ),
                    images: Field::from($node, 'images', static fn (array $keys): array => array_values(array_map('strval', $keys))),
                ),
                array_values($nodes),
            )),
            images: Field::from($keep('images'), 'images', static fn (array $nodes): array => self::mediaLines($nodes, $defaultLocale, $locales)),
            documents: Field::from($keep('documents'), 'documents', static fn (array $nodes): array => self::mediaLines($nodes, $defaultLocale, $locales)),
            videos: Field::from($keep('videos'), 'videos', static fn (array $nodes): array => array_map(
                static fn (array $node): VideoLine => new VideoLine(
                    (string) $node['source'],
                    Field::from($node, 'visible', static fn (mixed $value): bool => (bool) $value),
                    isset($node['position']) ? (int) $node['position'] : null,
                    I18nBag::fromNode($node, VideoLine::TEXT_FIELDS, $defaultLocale, $locales),
                ),
                array_values($nodes),
            )),
            accessories: Field::from($keep('accessories'), 'accessories', static fn (array $nodes): array => array_map(
                static fn (array $node): AccessoryLine => new AccessoryLine(
                    trim((string) $node['ref']),
                    isset($node['position']) ? (int) $node['position'] : null,
                ),
                array_values($nodes),
            )),
        );
    }

    /**
     * @param list<string>|null $locales
     *
     * @return list<MediaLine>
     */
    private static function mediaLines(array $nodes, string $defaultLocale, ?array $locales): array
    {
        return array_map(
            static fn (array $node): MediaLine => new MediaLine(
                isset($node['_key']) ? (string) $node['_key'] : null,
                (string) $node['source'],
                Field::from($node, 'visible', static fn (mixed $value): bool => (bool) $value),
                isset($node['position']) ? (int) $node['position'] : null,
                I18nBag::fromNode($node, MediaLine::TEXT_FIELDS, $defaultLocale, $locales),
            ),
            array_values($nodes),
        );
    }

    /**
     * Free feature values are product content: the locale selection applies to them.
     *
     * @param list<string>|null $locales
     */
    private static function filterLocales(array $node, ?array $locales): array
    {
        if (null === $locales || !\is_array($node['i18n'] ?? null)) {
            return $node;
        }

        $node['i18n'] = array_intersect_key($node['i18n'], array_flip($locales));

        return $node;
    }
}
