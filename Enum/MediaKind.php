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
        return array_keys($this->extensions());
    }

    /**
     * The extension of a stored file comes from its detected type, never from the source name: a
     * "picture.php" holding a valid PNG is stored as ".png".
     *
     * @return array<string, string> MIME type => extension
     */
    public function extensions(): array
    {
        return match ($this) {
            self::IMAGE => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'],
            self::DOCUMENT => ['application/pdf' => 'pdf'],
        };
    }
}
