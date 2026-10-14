<?php

declare(strict_types=1);

namespace JsonLinesImporter\Reader;

use JsonLinesImporter\Exception\ImportException;

/**
 * Streams a JSON Lines file line by line from a byte offset, without loading it whole.
 */
final readonly class JsonLinesReader
{
    private const UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * @return \Generator<int, RawLine> keyed by nothing meaningful; the line number is carried by RawLine
     *
     * @throws ImportException when the file cannot be opened
     */
    public function read(string $path, int $offset = 0, int $firstLineNumber = 1): \Generator
    {
        $handle = @fopen($path, 'r');

        if (false === $handle) {
            throw new ImportException(\sprintf('The file %s cannot be opened.', basename($path)));
        }

        try {
            if ($offset > 0) {
                fseek($handle, $offset);
            }

            $lineNumber = $firstLineNumber;

            while (false !== ($content = fgets($handle))) {
                $nextOffset = (int) ftell($handle);

                if (0 === $offset && 1 === $lineNumber && str_starts_with($content, self::UTF8_BOM)) {
                    $content = substr($content, \strlen(self::UTF8_BOM));
                }

                yield new RawLine($lineNumber, rtrim($content, "\r\n"), $nextOffset);

                ++$lineNumber;
            }
        } finally {
            fclose($handle);
        }
    }
}
