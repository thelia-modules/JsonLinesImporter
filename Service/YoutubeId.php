<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service;

/**
 * Extracts the 11 character identifier of a YouTube video from the forms met in practice:
 * youtube.com/watch?v=<id>, youtu.be/<id> (query string included), youtube.com/shorts/<id>,
 * youtube.com/embed/<id>.
 */
final readonly class YoutubeId
{
    private const PATTERN = '~^(?:https?:)?//(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:[^#]*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~i';

    public function extract(string $url): ?string
    {
        return 1 === preg_match(self::PATTERN, trim($url), $matches) ? $matches[1] : null;
    }

    public function watchUrl(string $videoId): string
    {
        return 'https://www.youtube.com/watch?v='.$videoId;
    }
}
