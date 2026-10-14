<?php

declare(strict_types=1);

namespace JsonLinesImporter\Model;

use JsonLinesImporter\Enum\LineAction;
use JsonLinesImporter\Enum\LineResult;
use JsonLinesImporter\Model\Base\JsonlImportLine as BaseJsonlImportLine;

class JsonlImportLine extends BaseJsonlImportLine
{
    use JsonColumnTrait;

    /** @return list<string> */
    public function getErrorList(): array
    {
        return $this->decodeMessages($this->getErrors(), 'errors');
    }

    /** @param list<string> $messages */
    public function setErrorList(array $messages): static
    {
        return $this->setErrors([] === $messages ? null : self::encodeJsonColumn(array_values($messages)));
    }

    /** @return list<string> */
    public function getWarningList(): array
    {
        return $this->decodeMessages($this->getWarnings(), 'warnings');
    }

    /** @param list<string> $messages */
    public function setWarningList(array $messages): static
    {
        return $this->setWarnings([] === $messages ? null : self::encodeJsonColumn(array_values(array_unique($messages))));
    }

    public function getPayloadArray(): ?array
    {
        $payload = $this->decodeJsonColumn($this->getPayload(), \sprintf('jsonl_import_line#%d.payload', (int) $this->getId()));

        return \is_array($payload) ? $payload : null;
    }

    public function getActionEnum(): ?LineAction
    {
        return LineAction::tryFrom((string) $this->getAction());
    }

    public function getResultEnum(): LineResult
    {
        return LineResult::tryFrom((string) $this->getResult()) ?? LineResult::PENDING;
    }

    /** @return list<string> */
    private function decodeMessages(?string $rawValue, string $column): array
    {
        $messages = $this->decodeJsonColumn($rawValue, \sprintf('jsonl_import_line#%d.%s', (int) $this->getId(), $column));

        return \is_array($messages) ? array_values(array_map('strval', $messages)) : [];
    }
}
