<?php

declare(strict_types=1);

namespace JsonLinesImporter\Model;

use Thelia\Log\Tlog;

trait JsonColumnTrait
{
    /**
     * Decodes a JSON column. A corrupt value is logged then neutralized (null).
     */
    private function decodeJsonColumn(?string $rawValue, string $columnDescription): mixed
    {
        if (null === $rawValue || '' === trim($rawValue)) {
            return null;
        }

        try {
            return json_decode($rawValue, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            Tlog::getInstance()->error('JsonLinesImporter: invalid JSON column', [
                'column' => $columnDescription,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private static function encodeJsonColumn(mixed $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
