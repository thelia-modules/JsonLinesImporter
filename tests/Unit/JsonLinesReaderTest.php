<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Unit;

use JsonLinesImporter\Reader\JsonLinesReader;
use JsonLinesImporter\Reader\RawLine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JsonLinesReaderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'jsonl-test-');
        file_put_contents($this->path, "\xEF\xBB\xBF{\"ref\":\"A\"}\r\n\n{\"ref\":\"B\"}\n{\"ref\":\"C\"}");
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    #[Test]
    public function itStripsTheByteOrderMarkAndTheLineEndings(): void
    {
        $lines = iterator_to_array((new JsonLinesReader())->read($this->path), false);

        self::assertSame(['{"ref":"A"}', '', '{"ref":"B"}', '{"ref":"C"}'], array_map(static fn (RawLine $line): string => $line->content, $lines));
        self::assertSame([1, 2, 3, 4], array_map(static fn (RawLine $line): int => $line->number, $lines));
        self::assertTrue($lines[1]->isBlank());
    }

    #[Test]
    public function itResumesFromAnOffsetWithTheNextLineNumber(): void
    {
        $reader = new JsonLinesReader();
        $first = iterator_to_array($reader->read($this->path), false);
        $resumed = iterator_to_array($reader->read($this->path, $first[1]->nextOffset, 3), false);

        self::assertSame('{"ref":"B"}', $resumed[0]->content);
        self::assertSame(3, $resumed[0]->number);
        self::assertCount(2, $resumed);
    }
}
