<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Unit;

use JsonLinesImporter\Exception\ImportException;
use JsonLinesImporter\Service\MediaSourceDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MediaSourceDirectoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/jli-root-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/images/2026', 0o777, true);
        symlink(sys_get_temp_dir(), $this->root.'/escape');
    }

    protected function tearDown(): void
    {
        unlink($this->root.'/escape');
        rmdir($this->root.'/images/2026');
        rmdir($this->root.'/images');
        rmdir($this->root);
    }

    #[Test]
    public function noDirectoryIsDeclared(): void
    {
        self::assertNull($this->directories()->resolve(null));
        self::assertNull($this->directories()->resolve('  '));
    }

    #[Test]
    public function aDirectoryIsResolvedUnderTheRoot(): void
    {
        $real = realpath($this->root);

        self::assertSame($real.'/images', $this->directories()->resolve('images'));
        self::assertSame($real.'/images/2026', $this->directories()->resolve('images/2026/'));
        self::assertSame($real.'/images', $this->directories()->resolve($this->root.'/images'), 'an absolute path inside the root');
        self::assertSame($real, $this->directories()->resolve('.'), 'the root itself');
    }

    public static function refusedDirectories(): iterable
    {
        yield 'parent' => ['..'];
        yield 'climbing out' => ['images/../..'];
        yield 'absolute outside' => ['/etc'];
        yield 'link out of the root' => ['escape'];
        yield 'missing' => ['videos'];
    }

    #[Test]
    #[DataProvider('refusedDirectories')]
    public function aDirectoryOutsideTheRootIsRefused(string $directory): void
    {
        $this->expectException(ImportException::class);

        $this->directories()->resolve($directory);
    }

    #[Test]
    public function theMessageDoesNotTellWhetherAPathOutsideTheRootExists(): void
    {
        $messages = [];

        foreach (['/etc', '/no-such-directory'] as $directory) {
            try {
                $this->directories()->resolve($directory);
            } catch (ImportException $exception) {
                $messages[] = $exception->key();
            }
        }

        self::assertCount(2, $messages);
        self::assertSame($messages[0], $messages[1]);
    }

    private function directories(): MediaSourceDirectory
    {
        return new MediaSourceDirectory($this->root);
    }
}
