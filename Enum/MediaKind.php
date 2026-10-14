<?php

declare(strict_types=1);

namespace JsonLinesImporter\Enum;

enum MediaKind: string
{
    case IMAGE = 'image';
    case DOCUMENT = 'document';
    case VIDEO = 'video';

    /** @return list<string> */
    public function allowedMimeTypes(): array
    {
        return array_keys($this->extensions());
    }

    /**
     * The extension of a stored file comes from its detected type, never from the source name: a
     * "picture.php" holding a valid PNG is stored as ".png".
     *
     * Videos: the three formats every current browser plays, the default list of the core. A shop that
     * narrows its own list (video_upload_allowed_mime_types) is applied when the video is written.
     *
     * @return array<string, string> MIME type => extension
     */
    public function extensions(): array
    {
        return match ($this) {
            self::IMAGE => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'],
            self::DOCUMENT => ['application/pdf' => 'pdf'],
            self::VIDEO => ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/ogg' => 'ogv'],
        };
    }

    public function maxBytes(): int
    {
        return match ($this) {
            self::IMAGE, self::DOCUMENT => 20 * 1024 * 1024,
            self::VIDEO => 200 * 1024 * 1024,
        };
    }

    /** Longest time a download may take, redirections included. */
    public function maxDurationSeconds(): int
    {
        return match ($this) {
            self::IMAGE, self::DOCUMENT => 60,
            self::VIDEO => 600,
        };
    }
}
