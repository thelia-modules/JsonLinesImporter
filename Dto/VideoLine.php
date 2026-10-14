<?php

declare(strict_types=1);

namespace JsonLinesImporter\Dto;

/**
 * A video of the product: a platform address (YouTube, Vimeo, Dailymotion) or a video file.
 */
final readonly class VideoLine
{
    public const TEXT_FIELDS = ['title', 'alt', 'chapo', 'description', 'postscriptum'];

    public function __construct(
        public string $source,
        public Field $visible,
        public ?int $position,
        public I18nBag $i18n,
    ) {
    }
}
