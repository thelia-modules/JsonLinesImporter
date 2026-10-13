<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Unit;

use JsonLinesImporter\Enum\MediaKind;
use JsonLinesImporter\Exception\ImportException;
use JsonLinesImporter\Service\MediaFetcher;
use JsonLinesImporter\Service\MediaSourceDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MediaFetcherTest extends TestCase
{
    /** The import root of the shop. */
    private string $root;

    private string $directory;

    private string $outside;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/jli-media-'.bin2hex(random_bytes(4));
        $this->directory = $this->root.'/images';
        $this->outside = $this->root.'-outside';
        mkdir($this->directory, 0o777, true);
        mkdir($this->outside);
        // A real PNG (1x1) under a name that would run as PHP.
        file_put_contents($this->directory.'/picture.php', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        // The file type box an MP4 starts with, enough for the type to be detected.
        file_put_contents($this->directory.'/clip.bin', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64));
    }

    protected function tearDown(): void
    {
        array_map('unlink', [...(glob($this->directory.'/*') ?: []), ...(glob($this->outside.'/*') ?: [])]);
        rmdir($this->directory);
        rmdir($this->root);
        rmdir($this->outside);
    }

    #[Test]
    public function aDirectoryOutsideTheImportRootIsNotRead(): void
    {
        copy($this->directory.'/picture.php', $this->outside.'/picture.php');

        $this->expectException(ImportException::class);

        $this->fetcher()->fetch('picture.php', MediaKind::IMAGE, $this->outside);
    }

    #[Test]
    public function aDirectoryIsGivenRelativeToTheImportRoot(): void
    {
        $file = $this->fetcher()->fetch('picture.php', MediaKind::IMAGE, 'images');

        self::assertSame('png', $file->extension());
        $file->discard();
    }

    private function fetcher(): MediaFetcher
    {
        return new MediaFetcher(null, new MediaSourceDirectory($this->root));
    }

    #[Test]
    public function theStoredExtensionComesFromTheDetectedType(): void
    {
        $file = $this->fetcher()->fetch('picture.php', MediaKind::IMAGE, $this->directory);

        self::assertSame('png', $file->extension());
        self::assertSame('picture.png', $file->safeName());
        $file->discard();
    }

    #[Test]
    public function aPathOutsideTheDeclaredDirectoryIsNotRead(): void
    {
        $this->expectException(ImportException::class);

        $this->fetcher()->fetch('/etc/hostname', MediaKind::IMAGE, $this->directory);
    }

    #[Test]
    public function aPathIsRefusedWithoutDeclaredDirectory(): void
    {
        $this->expectException(ImportException::class);

        $this->fetcher()->fetch($this->directory.'/picture.php', MediaKind::IMAGE);
    }

    #[Test]
    public function aPrivateAddressIsNotDownloaded(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessageMatches('/download failed/');

        $this->fetcher()->fetch('http://127.0.0.1/image.png', MediaKind::IMAGE);
    }

    #[Test]
    public function aVideoIsStoredWithTheExtensionOfItsDetectedType(): void
    {
        $file = $this->fetcher()->fetch('clip.bin', MediaKind::VIDEO, $this->directory);

        self::assertSame('video/mp4', $file->mimeType);
        self::assertSame('clip.mp4', $file->safeName());
        $file->discard();
    }

    #[Test]
    public function anImageIsNotAcceptedAsAVideo(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessageMatches('/unexpected content type image\/png/');

        $this->fetcher()->fetch('picture.php', MediaKind::VIDEO, $this->directory);
    }
}
