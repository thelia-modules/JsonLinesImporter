<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Unit;

use JsonLinesImporter\Enum\MediaKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MediaKindTest extends TestCase
{
    #[Test]
    public function aVideoIsOneOfTheFormatsEveryBrowserPlays(): void
    {
        self::assertSame(['video/mp4', 'video/webm', 'video/ogg'], MediaKind::VIDEO->allowedMimeTypes());
        self::assertSame('ogv', MediaKind::VIDEO->extensions()['video/ogg']);
    }

    #[Test]
    public function aVideoMayBeLargerAndLongerToFetchThanAnImage(): void
    {
        self::assertSame(200 * 1024 * 1024, MediaKind::VIDEO->maxBytes());
        self::assertSame(600, MediaKind::VIDEO->maxDurationSeconds());
        self::assertSame(20 * 1024 * 1024, MediaKind::IMAGE->maxBytes());
        self::assertSame(60, MediaKind::DOCUMENT->maxDurationSeconds());
    }
}
