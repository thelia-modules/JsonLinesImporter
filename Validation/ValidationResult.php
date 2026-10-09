<?php

declare(strict_types=1);

namespace JsonLinesImporter\Validation;

final class ValidationResult
{
    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    /** @param list<string> $messages */
    public function addErrors(array $messages): void
    {
        foreach ($messages as $message) {
            $this->addError($message);
        }
    }

    public function isValid(): bool
    {
        return [] === $this->errors;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }
}
