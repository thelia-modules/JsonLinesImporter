<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Unit;

use JsonLinesImporter\Enum\MediaKind;
use JsonLinesImporter\Exception\ImportException;
use JsonLinesImporter\Service\MediaFetcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MediaFetcherTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/jli-media-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        // A real PNG (1x1) under a name that would run as PHP.
        file_put_contents($this->directory.'/picture.php', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        // The file type box an MP4 starts with, enough for the type to be detected.
        file_put_contents($this->directory.'/clip.bin', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
    }

    #[Test]
    public function theStoredExtensionComesFromTheDetectedType(): void
    {
        $file = (new MediaFetcher())->fetch('picture.php', MediaKind::IMAGE, $this->directory);

        self::assertSame('png', $file->extension());
        self::assertSame('picture.png', $file->safeName());
        $file->discard();
    }

    #[Test]
    public function aPathOutsideTheDeclaredDirectoryIsNotRead(): void
    {
        $this->expectException(ImportException::class);

        (new MediaFetcher())->fetch('/etc/hostname', MediaKind::IMAGE, $this->directory);
    }

    #[Test]
    public function aPathIsRefusedWithoutDeclaredDirectory(): void
    {
        $this->expectException(ImportException::class);

        (new MediaFetcher())->fetch($this->directory.'/picture.php', MediaKind::IMAGE);
    }

    #[Test]
    public function aPrivateAddressIsNotDownloaded(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessageMatches('/download failed/');

        (new MediaFetcher())->fetch('http://127.0.0.1/image.png', MediaKind::IMAGE);
    }

    #[Test]
    public function aVideoIsStoredWithTheExtensionOfItsDetectedType(): void
    {
        $file = (new MediaFetcher())->fetch('clip.bin', MediaKind::VIDEO, $this->directory);

        self::assertSame('video/mp4', $file->mimeType);
        self::assertSame('clip.mp4', $file->safeName());
        $file->discard();
    }

    #[Test]
    public function anImageIsNotAcceptedAsAVideo(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessageMatches('/unexpected content type image\/png/');

        (new MediaFetcher())->fetch('picture.php', MediaKind::VIDEO, $this->directory);
    }
}
