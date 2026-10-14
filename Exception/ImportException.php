<?php

declare(strict_types=1);

namespace JsonLinesImporter\Exception;

/**
 * The message is a translation key: getMessage() gives it in English with its parameters replaced (logs,
 * console), key() and parameters() let the back-office translate it.
 */
final class ImportException extends \RuntimeException
{
    /** @param array<string, string|int> $parameters */
    public function __construct(
        private readonly string $key,
        private readonly array $parameters = [],
    ) {
        parent::__construct(strtr($key, array_map('strval', $parameters)));
    }

    public function key(): string
    {
        return $this->key;
    }

    /** @return array<string, string|int> */
    public function parameters(): array
    {
        return $this->parameters;
    }
}
