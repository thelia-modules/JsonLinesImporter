<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service;

use JsonLinesImporter\Dto\CategoryLine;
use JsonLinesImporter\Dto\FeatureLine;
use JsonLinesImporter\Dto\Field;
use JsonLinesImporter\Dto\ImportParameters;
use JsonLinesImporter\Dto\Label;
use JsonLinesImporter\Dto\MediaLine;
use JsonLinesImporter\Dto\ProductLine;
use JsonLinesImporter\Dto\VariantLine;
use JsonLinesImporter\Dto\VideoLine;
use JsonLinesImporter\Enum\ReferentialType;
use JsonLinesImporter\Enum\VideoDestination;
use JsonLinesImporter\Model\JsonlImportLine;

/**
 * Step 4: the values of a line that will be written, by group, mappings applied. No comparison with the
 * current values of the shop.
 */
final readonly class PreviewBuilder
{
    private const TEXT_PREVIEW = 120;

    public function __construct(
        private ProductLineFactory $lineFactory,
        private ReferentialResolver $resolver,
        private ShopContext $shopContext,
        private YoutubeId $youtubeId,
        private UrlPathNormalizer $normalizer,
    ) {
    }

    /**
     * @return array{groups: array<string, list<string>>, warnings: list<string>}
     */
    public function build(JsonlImportLine $row, ImportParameters $parameters): array
    {
        $line = $this->lineFactory->create((array) $row->getPayloadArray(), $this->shopContext->defaultLocale(), $parameters);
        $groups = [];
        $warnings = $row->getWarningList();

        foreach (['visible', 'virtual', 'position'] as $key) {
            $field = $line->{$key};

            if ($field->present) {
                $groups['Product'][] = $key.': '.self::scalar($field->value);
            }
        }

        foreach ($line->i18n->values as $locale => $fields) {
            foreach ($fields as $name => $value) {
                if ('title' === $name && !$parameters->isKeySelected('title') && 'update' === $row->getAction()) {
                    continue;
                }

                $groups['Translatable contents'][] = \sprintf('%s (%s): %s', $name, $locale, self::text($value));
            }
        }

        $this->sale($line, $groups);
        $this->referentials($line, $groups, $warnings);
        $this->medias('Images', $line->images, $groups);
        $this->medias('Documents', $line->documents, $groups);
        $this->videos($line, $parameters, $groups, $warnings);

        if ($line->accessories->present) {
            $groups['Accessories'][] = null === $line->accessories->value ? 'null: emptied' : implode(', ', array_map(static fn ($accessory): string => $accessory->ref, $line->accessories->value));
        }

        foreach ($line->urls->values as $locale => $fields) {
            $paths = null === ($fields['url'] ?? null) ? [] : $this->normalizer->paths((string) $fields['url']);

            if ([] === $paths) {
                $warnings[] = \sprintf('url (%s) cannot become a redirection', $locale);
            }

            foreach ($paths as $path) {
                $groups['Source URL'][] = \sprintf('/%s (%s) → 301', $path, $locale);
            }
        }

        return ['groups' => $groups, 'warnings' => array_values(array_unique($warnings))];
    }

    private function sale(ProductLine $line, array &$groups): void
    {
        $sale = $line->sale;

        foreach (['ean_code' => $sale->eanCode, 'quantity' => $sale->quantity, 'weight' => $sale->weight, 'newness' => $sale->newness, 'promo' => $sale->promo, 'price' => $sale->price, 'promo_price' => $sale->promoPrice] as $name => $field) {
            if ($field->present) {
                $groups['Sale elements and prices'][] = $name.': '.self::scalar($field->value);
            }
        }

        if ($sale->prices->hasValue()) {
            foreach ($sale->prices->value as $price) {
                $groups['Sale elements and prices'][] = \sprintf('price %s: %s', $price->currency, $price->fromDefaultCurrency ? 'from the default currency' : $price->price);
            }
        }

        if ($line->variants->hasValue()) {
            /** @var VariantLine $variant */
            foreach ($line->variants->value as $variant) {
                $combination = implode(', ', array_map(static fn ($item): string => $item->attribute->matchingTitle.' = '.$item->value->matchingTitle, $variant->combination));
                $groups['Variants'][] = trim(\sprintf('%s%s %s', $variant->ref ?? $line->ref, $variant->default ? ' (default)' : '', $combination));
            }
        }
    }

    /** @param list<string> $warnings */
    private function referentials(ProductLine $line, array &$groups, array &$warnings): void
    {
        if ($line->taxRule->hasValue()) {
            $groups['Tax rule'][] = $this->resolved(ReferentialType::TAX_RULE, $line->taxRule->value, $warnings);
        }

        if ($line->brand->present) {
            $groups['Brand'][] = null === $line->brand->value ? 'null: removed' : $this->resolved(ReferentialType::BRAND, $line->brand->value, $warnings);
        }

        if ($line->categories->hasValue()) {
            /** @var CategoryLine $category */
            foreach ($line->categories->value as $category) {
                $groups['Categories'][] = $this->resolved(ReferentialType::CATEGORY, $category->label, $warnings).($category->default ? ' (default)' : '');
            }
        }

        if ($line->features->hasValue()) {
            /** @var FeatureLine $feature */
            foreach ($line->features->value as $feature) {
                $groups['Features'][] = $this->resolved(ReferentialType::FEATURE, $feature->feature, $warnings).': '.$feature->value->matchingTitle.($feature->free ? ' (free value)' : '');
            }
        } elseif ($line->features->isNull()) {
            $groups['Features'][] = 'null: emptied';
        }
    }

    /** @param list<string> $warnings */
    private function resolved(ReferentialType $type, Label $label, array &$warnings): string
    {
        $id = $this->resolver->resolve($type, $label);

        if (null === $id) {
            $warnings[] = \sprintf('%s "%s" ignored', $type->label(), $label->matchingTitle);

            return $label->matchingTitle.' (ignored)';
        }

        return \sprintf('%s → #%d', $label->matchingTitle, $id);
    }

    private function medias(string $group, Field $field, array &$groups): void
    {
        if (!$field->present) {
            return;
        }

        if (null === $field->value) {
            $groups[$group][] = 'null: files imported by the module removed';

            return;
        }

        /** @var MediaLine $media */
        foreach ($field->value as $media) {
            $groups[$group][] = $media->source;
        }
    }

    /** @param list<string> $warnings */
    private function videos(ProductLine $line, ImportParameters $parameters, array &$groups, array &$warnings): void
    {
        if (!$line->videos->present) {
            return;
        }

        $destination = VideoDestination::FEATURE === $parameters->videoDestination ? 'feature' : 'blocks';

        /** @var VideoLine $video */
        foreach ($line->videos->value ?? [] as $video) {
            if (null === $this->youtubeId->extract($video->source)) {
                $warnings[] = 'Video ignored, not a YouTube address: '.$video->source;

                continue;
            }

            $groups['Videos'][] = $video->source.' → '.$destination;
        }
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            null === $value => 'null',
            \is_bool($value) => $value ? 'yes' : 'no',
            default => (string) $value,
        };
    }

    private static function text(?string $value): string
    {
        if (null === $value) {
            return 'null (emptied)';
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));

        return mb_strlen($text) > self::TEXT_PREVIEW ? mb_substr($text, 0, self::TEXT_PREVIEW).'…' : $text;
    }
}
