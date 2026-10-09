<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service;

/**
 * The keys of a line that step 2 lets the user select, by group.
 *
 * One box per first level key of the product object; each translatable field of the product has its
 * own box, valid for the root and for every locale. Sub keys of collections have no box.
 */
final readonly class DataCatalog
{
    public const TRANSLATABLE_KEYS = ['title', 'chapo', 'description', 'postscriptum', 'meta_title', 'meta_description', 'meta_keywords', 'url'];

    /** @var array<string, list<string>> group label => keys */
    public const GROUPS = [
        'Product' => ['visible', 'virtual', 'position'],
        'Translatable contents' => ['title', 'chapo', 'description', 'postscriptum', 'meta_title', 'meta_description', 'meta_keywords'],
        'Sale elements and prices' => ['ean_code', 'quantity', 'weight', 'newness', 'promo', 'price', 'promo_price', 'prices'],
        'Tax rule' => ['tax_rule'],
        'Categories' => ['categories'],
        'Brand' => ['brand'],
        'Features' => ['features'],
        'Variants' => ['variants'],
        'Images' => ['images'],
        'Documents' => ['documents'],
        'Videos' => ['videos'],
        'Accessories' => ['accessories'],
        'Source URL' => ['url'],
    ];

    /**
     * Keys a line carries, translatable fields found at the root or in any i18n block.
     *
     * @param array<string, mixed> $line
     *
     * @return list<string>
     */
    public function keysOf(array $line): array
    {
        $keys = array_diff(array_keys($line), ['ref', 'i18n']);

        if (\is_array($line['i18n'] ?? null)) {
            foreach ($line['i18n'] as $block) {
                if (\is_array($block)) {
                    $keys = [...$keys, ...array_keys($block)];
                }
            }
        }

        return array_values(array_unique(array_map('strval', $keys)));
    }

    /**
     * Locales a line carries in any i18n block, its own or the one of a nested node.
     *
     * @return list<string>
     */
    public function localesOf(array $line): array
    {
        $locales = [];

        $this->collectLocales($line, $locales);

        return array_values(array_unique($locales));
    }

    /** @param list<string> $locales */
    private function collectLocales(array $node, array &$locales): void
    {
        foreach ($node as $key => $value) {
            if ('i18n' === $key && \is_array($value)) {
                foreach (array_keys($value) as $locale) {
                    $locales[] = (string) $locale;
                }

                continue;
            }

            if (\is_array($value)) {
                $this->collectLocales($value, $locales);
            }
        }
    }
}
