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
}
