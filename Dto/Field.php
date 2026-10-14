<?php

declare(strict_types=1);

namespace JsonLinesImporter\Dto;

/**
 * A key of a line in one of its three states: absent (left unchanged), null (emptied), or a value.
 * The whole collection semantics of the format rests on the difference: a nullable PHP value alone
 * cannot tell "absent" from "null".
 */
final readonly class Field
{
    private function __construct(
        public bool $present,
        public mixed $value,
    ) {
    }

    public static function absent(): self
    {
        return new self(false, null);
    }

    public static function of(mixed $value): self
    {
        return new self(true, $value);
    }

    /** @param array<string, mixed> $data */
    public static function from(array $data, string $key, ?\Closure $map = null): self
    {
        if (!\array_key_exists($key, $data)) {
            return self::absent();
        }

        $value = $data[$key];

        return self::of(null !== $value && null !== $map ? $map($value) : $value);
    }

    public function isAbsent(): bool
    {
        return !$this->present;
    }

    public function isNull(): bool
    {
        return $this->present && null === $this->value;
    }

    public function hasValue(): bool
    {
        return $this->present && null !== $this->value;
    }

    public function or(mixed $default): mixed
    {
        return $this->hasValue() ? $this->value : $default;
    }
}
