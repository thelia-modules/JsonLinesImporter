<?php

declare(strict_types=1);

namespace JsonLinesImporter\Enum;

enum MediaKind: string
{
    case IMAGE = 'image';
    case DOCUMENT = 'document';

    /** @return list<string> */
    public function allowedMimeTypes(): array
    {
        return match ($this) {
            self::IMAGE => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            self::DOCUMENT => ['application/pdf'],
        };
    }
}
