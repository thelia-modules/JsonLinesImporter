<?php

declare(strict_types=1);

namespace JsonLinesImporter\Validation;

use JsonLinesImporter\Service\ShopContext;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * The rules of a line that the JSON Schema cannot express: references unique in the file and in the
 * shop, variants identified when there are several, image keys, locales and currencies of the shop.
 */
final readonly class LineValidator
{
    public function __construct(
        private SchemaValidator $schemaValidator,
        private ShopContext $shopContext,
    ) {
    }

    /**
     * @param callable(string):bool              $isRefAlreadyInFile        a previous line carries this product ref
     * @param callable(string, string):bool $isVariantRefTakenInFile a previous line of another product carries this variant ref
     */
    public function validate(string $content, callable $isRefAlreadyInFile, callable $isVariantRefTakenInFile): ValidationResult
    {
        $result = new ValidationResult();

        try {
            $object = json_decode($content, false, 512, \JSON_THROW_ON_ERROR);
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $result->addError('(root): invalid JSON ('.$exception->getMessage().')');

            return $result;
        }

        if (!\is_array($data) || array_is_list($data)) {
            $result->addError('(root): a line must hold a JSON object');

            return $result;
        }

        $result->addErrors($this->schemaValidator->validate($object));

        if (!$result->isValid()) {
            return $result;
        }

        $ref = trim((string) $data['ref']);

        if ($isRefAlreadyInFile($ref)) {
            $result->addError(\sprintf('/ref: the reference %s is already used by a previous line', $ref));
        }

        $this->validateVariants($data, $ref, $result, $isVariantRefTakenInFile);
        $this->validateImageKeys($data, $result);
        $this->checkLocales($data, $result);
        $this->checkCurrencies($data, $result);

        return $result;
    }

    /** @param callable(string, string):bool $isVariantRefTakenInFile */
    private function validateVariants(array $data, string $ref, ValidationResult $result, callable $isVariantRefTakenInFile): void
    {
        $variants = $data['variants'] ?? null;

        if (!\is_array($variants) || [] === $variants) {
            return;
        }

        $seen = [];

        foreach (array_values($variants) as $index => $variant) {
            $variantRef = isset($variant['ref']) ? trim((string) $variant['ref']) : null;

            if (null === $variantRef) {
                if (\count($variants) > 1) {
                    $result->addError(\sprintf('/variants/%d/ref: required when the product has several variants', $index));

                    continue;
                }

                $variantRef = $ref;
            }

            if (isset($seen[$variantRef])) {
                $result->addError(\sprintf('/variants/%d/ref: the variant reference %s is used twice in the line', $index, $variantRef));
            }

            $seen[$variantRef] = true;

            if ($isVariantRefTakenInFile($variantRef, $ref)) {
                $result->addError(\sprintf('/variants/%d/ref: the variant reference %s is used by another product of the file', $index, $variantRef));
            } elseif ($this->isVariantRefTakenInShop($variantRef, $ref)) {
                $result->addError(\sprintf('/variants/%d/ref: the variant reference %s belongs to another product of the shop', $index, $variantRef));
            }
        }
    }

    private function validateImageKeys(array $data, ValidationResult $result): void
    {
        $keys = [];

        foreach (array_values((array) ($data['images'] ?? [])) as $index => $image) {
            if (!isset($image['_key'])) {
                continue;
            }

            if (isset($keys[$image['_key']])) {
                $result->addError(\sprintf('/images/%d/_key: the key %s is used twice', $index, $image['_key']));
            }

            $keys[$image['_key']] = true;
        }

        foreach (array_values((array) ($data['variants'] ?? [])) as $variantIndex => $variant) {
            foreach (array_values((array) ($variant['images'] ?? [])) as $imageIndex => $key) {
                if (!isset($keys[$key])) {
                    $result->addError(\sprintf('/variants/%d/images/%d: no image of the product has the key %s', $variantIndex, $imageIndex, $key));
                }
            }
        }
    }

    private function checkLocales(array $data, ValidationResult $result): void
    {
        $locales = [];

        self::collectLocales($data, $locales);

        foreach (array_unique($locales) as $locale) {
            if (!$this->shopContext->isKnownLocale($locale)) {
                $result->addWarning(\sprintf('The locale %s is unknown to the shop: its contents are ignored', $locale));
            } elseif (!$this->shopContext->isActiveLocale($locale)) {
                $result->addWarning(\sprintf('The locale %s is not active in the shop', $locale));
            }
        }
    }

    private function checkCurrencies(array $data, ValidationResult $result): void
    {
        $nodes = [$data, ...array_values((array) ($data['variants'] ?? []))];

        foreach ($nodes as $node) {
            foreach ((array) ($node['prices'] ?? []) as $price) {
                $code = (string) ($price['currency'] ?? '');

                if (null === $this->shopContext->currencyId($code)) {
                    $result->addWarning(\sprintf('The currency %s is unknown to the shop: its price is ignored', $code));
                }
            }
        }
    }

    private function isVariantRefTakenInShop(string $variantRef, string $productRef): bool
    {
        return ProductSaleElementsQuery::create()
            ->filterByRef($variantRef)
            ->useProductQuery()
                ->filterByRef($productRef, Criteria::NOT_EQUAL)
            ->endUse()
            ->exists();
    }

    /** @param list<string> $locales */
    private static function collectLocales(array $node, array &$locales): void
    {
        foreach ($node as $key => $value) {
            if ('i18n' === $key && \is_array($value)) {
                array_push($locales, ...array_map('strval', array_keys($value)));

                continue;
            }

            if (\is_array($value)) {
                self::collectLocales($value, $locales);
            }
        }
    }
}
