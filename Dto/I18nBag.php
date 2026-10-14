<?php

declare(strict_types=1);

namespace JsonLinesImporter\Dto;

/**
 * Translatable content of one node of a line, by locale then field.
 *
 * Keys at the root of the node target the default locale of the shop; an `i18n.<locale>` block wins
 * over them for the same locale. A locale absent from the bag is left untouched, and inside a locale
 * only the fields present are written (a null value empties the field).
 */
final readonly class I18nBag
{
    /** @param array<string, array<string, ?string>> $values locale => field => value */
    public function __construct(
        public array $values = [],
    ) {
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string>         $fields
     * @param list<string>|null    $allowedLocales null = every locale
     */
    public static function fromNode(array $node, array $fields, string $defaultLocale, ?array $allowedLocales = null): self
    {
        $values = [];

        foreach ($fields as $field) {
            if (\array_key_exists($field, $node)) {
                $values[$defaultLocale][$field] = self::stringOrNull($node[$field]);
            }
        }

        if (\is_array($node['i18n'] ?? null)) {
            foreach ($node['i18n'] as $locale => $block) {
                if (!\is_array($block)) {
                    continue;
                }

                foreach ($fields as $field) {
                    if (\array_key_exists($field, $block)) {
                        $values[(string) $locale][$field] = self::stringOrNull($block[$field]);
                    }
                }
            }
        }

        if (null !== $allowedLocales) {
            $values = array_intersect_key($values, array_flip($allowedLocales));
        }

        return new self($values);
    }

    /** @return list<string> */
    public function locales(): array
    {
        return array_keys($this->values);
    }

    public function has(string $locale, string $field): bool
    {
        return \array_key_exists($field, $this->values[$locale] ?? []);
    }

    public function get(string $locale, string $field): ?string
    {
        return $this->values[$locale][$field] ?? null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->values;
    }

    public function hasField(string $field): bool
    {
        foreach ($this->values as $fields) {
            if (\array_key_exists($field, $fields)) {
                return true;
            }
        }

        return false;
    }

    /**
     * First non empty value of a field: the default locale first, then the locales in file order.
     */
    public function firstValue(string $field, string $defaultLocale): ?string
    {
        $candidate = $this->get($defaultLocale, $field);

        if (null !== $candidate && '' !== trim($candidate)) {
            return $candidate;
        }

        foreach ($this->values as $fields) {
            $candidate = $fields[$field] ?? null;

            if (null !== $candidate && '' !== trim($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function without(string $field): self
    {
        $values = [];

        foreach ($this->values as $locale => $fields) {
            unset($fields[$field]);

            if ([] !== $fields) {
                $values[$locale] = $fields;
            }
        }

        return new self($values);
    }

    /** @param list<string> $fields */
    public function only(array $fields): self
    {
        $values = [];

        foreach ($this->values as $locale => $localeFields) {
            $kept = array_intersect_key($localeFields, array_flip($fields));

            if ([] !== $kept) {
                $values[$locale] = $kept;
            }
        }

        return new self($values);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return null === $value ? null : (string) $value;
    }
}
