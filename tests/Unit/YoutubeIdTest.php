<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Unit;

use JsonLinesImporter\Service\YoutubeId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class YoutubeIdTest extends TestCase
{
    public static function addresses(): iterable
    {
        yield 'short link' => ['https://youtu.be/gIj8qHGrk9A', 'gIj8qHGrk9A'];
        yield 'short link with parameters' => ['https://youtu.be/gIj8qHGrk9A?feature=shared', 'gIj8qHGrk9A'];
        yield 'shorts' => ['https://www.youtube.com/shorts/aBcDeFgHi01', 'aBcDeFgHi01'];
        yield 'watch' => ['https://www.youtube.com/watch?v=zYxWvUtSr02', 'zYxWvUtSr02'];
        yield 'watch with other parameters' => ['https://www.youtube.com/watch?list=x&v=zYxWvUtSr02&t=3', 'zYxWvUtSr02'];
        yield 'not YouTube' => ['https://vimeo.com/123456789', null];
        yield 'identifier too short' => ['https://youtu.be/short', null];
    }

    #[Test]
    #[DataProvider('addresses')]
    public function itExtractsTheVideoIdentifier(string $address, ?string $expected): void
    {
        self::assertSame($expected, (new YoutubeId())->extract($address));
    }

    #[Test]
    public function theWatchAddressIsRebuiltFromTheIdentifier(): void
    {
        self::assertSame('https://www.youtube.com/watch?v=aBcDeFgHi01', (new YoutubeId())->watchUrl('aBcDeFgHi01'));
    }
}
