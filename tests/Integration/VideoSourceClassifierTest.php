<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Integration;

use JsonLinesImporter\Exception\ImportException;
use JsonLinesImporter\Service\VideoSourceClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Thelia\Domain\Media\Video\VideoProvider;
use Thelia\Domain\Media\Video\VideoProviderResolver;

/**
 * Integration suite for the resolver of the core it relies on; no database: the enabled platforms are
 * given to the resolver.
 */
final class VideoSourceClassifierTest extends TestCase
{
    public static function platformAddresses(): iterable
    {
        yield 'youtube watch' => ['https://www.youtube.com/watch?v=aBcDeFgHi01', VideoProvider::Youtube, 'aBcDeFgHi01'];
        yield 'youtube short link' => [' https://youtu.be/aBcDeFgHi01 ', VideoProvider::Youtube, 'aBcDeFgHi01'];
        yield 'vimeo' => ['https://vimeo.com/123456789', VideoProvider::Vimeo, '123456789'];
        yield 'dailymotion' => ['https://www.dailymotion.com/video/x8abc12', VideoProvider::Dailymotion, 'x8abc12'];
    }

    #[Test]
    #[DataProvider('platformAddresses')]
    public function aPlatformAddressIsAPlatformVideo(string $address, VideoProvider $provider, string $externalId): void
    {
        $source = $this->classifier()->classify($address);

        self::assertSame($provider, $source->provider);
        self::assertSame($externalId, $source->externalId);
        self::assertSame($provider->value.':'.$externalId, $source->identity());
    }

    #[Test]
    public function aPathOrAnAddressOfAVideoFileIsAFile(): void
    {
        self::assertTrue($this->classifier()->classify('clips/demo.mp4')->isFile());
        self::assertTrue($this->classifier()->classify('https://cdn.example.com/demo.webm?version=2')->isFile());
        self::assertSame('https://cdn.example.com/demo.webm?version=2', $this->classifier()->classify(' https://cdn.example.com/demo.webm?version=2 ')->identity());
    }

    #[Test]
    public function anAddressOfAPlatformTheShopDisabledIsRefused(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessageMatches('/Accepted platforms: YouTube\./');

        (new VideoSourceClassifier(new VideoProviderResolver('youtube')))->classify('https://vimeo.com/123456789');
    }

    #[Test]
    public function anyOtherPageIsRefused(): void
    {
        $this->expectException(ImportException::class);

        $this->classifier()->classify('https://example.com/a-page-about-the-lamp');
    }

    private function classifier(): VideoSourceClassifier
    {
        return new VideoSourceClassifier(new VideoProviderResolver('youtube,vimeo,dailymotion'));
    }
}
