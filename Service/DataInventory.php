<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service;

use JsonLinesImporter\Model\JsonlImport;
use JsonLinesImporter\Model\JsonlImportLineQuery;
use JsonLinesImporter\Service\Writer\HtmlImageWriter;

/**
 * What the valid lines of an import carry: the keys and locales of step 2, and the facts that decide
 * which parameters step 3 offers (local media paths, images of the HTML fields).
 */
final readonly class DataInventory
{
    public function __construct(
        private DataCatalog $catalog,
    ) {
    }

    /**
     * @return array{keys: array<string, int>, locales: array<string, int>, local_images: bool, local_documents: bool, local_videos: bool, html_images: bool, referentials: array<string, bool>}
     */
    public function build(JsonlImport $import): array
    {
        $keys = [];
        $locales = [];
        $localImages = false;
        $localDocuments = false;
        $localVideos = false;
        $htmlImages = false;
        $referentials = ['tax_rule' => false, 'category' => false, 'brand' => false, 'feature' => false, 'attribute' => false];

        $lines = JsonlImportLineQuery::create()
            ->filterByImportId($import->getId())
            ->filterByValid(1)
            ->select(['Payload'])
            ->find();

        foreach ($lines as $payload) {
            $line = json_decode((string) $payload, true);

            if (!\is_array($line)) {
                continue;
            }

            foreach ($this->catalog->keysOf($line) as $key) {
                $keys[$key] = ($keys[$key] ?? 0) + 1;
            }

            foreach ($this->catalog->localesOf($line) as $locale) {
                $locales[$locale] = ($locales[$locale] ?? 0) + 1;
            }

            $localImages = $localImages || self::hasLocalSource($line['images'] ?? null);
            $localDocuments = $localDocuments || self::hasLocalSource($line['documents'] ?? null);
            $localVideos = $localVideos || self::hasLocalSource($line['videos'] ?? null);
            $htmlImages = $htmlImages || self::hasHtmlImages($line);

            $referentials['tax_rule'] = $referentials['tax_rule'] || \is_array($line['tax_rule'] ?? null);
            $referentials['category'] = $referentials['category'] || [] !== ($line['categories'] ?? []);
            $referentials['brand'] = $referentials['brand'] || \is_array($line['brand'] ?? null);
            $referentials['feature'] = $referentials['feature'] || [] !== ($line['features'] ?? []);

            foreach ((array) ($line['variants'] ?? []) as $variant) {
                $referentials['attribute'] = $referentials['attribute'] || [] !== ($variant['combination'] ?? []);
            }
        }

        return [
            'keys' => $keys,
            'locales' => $locales,
            'local_images' => $localImages,
            'local_documents' => $localDocuments,
            'local_videos' => $localVideos,
            'html_images' => $htmlImages,
            'referentials' => $referentials,
        ];
    }

    private static function hasLocalSource(mixed $medias): bool
    {
        foreach ((array) $medias as $media) {
            if (!\is_array($media)) {
                continue;
            }

            // The file of an image may come from an i18n block as well.
            $sources = [$media['source'] ?? null, ...array_column(array_filter((array) ($media['i18n'] ?? []), 'is_array'), 'source')];

            foreach ($sources as $source) {
                if (null !== $source && !MediaFetcher::isRemote((string) $source)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function hasHtmlImages(array $line): bool
    {
        $nodes = [$line, ...array_values(\is_array($line['i18n'] ?? null) ? $line['i18n'] : [])];

        foreach ($nodes as $node) {
            foreach (HtmlImageWriter::HTML_FIELDS as $field) {
                if (\is_string($node[$field] ?? null) && HtmlImageWriter::hasExternalImages($node[$field], null)) {
                    return true;
                }
            }
        }

        return false;
    }
}
