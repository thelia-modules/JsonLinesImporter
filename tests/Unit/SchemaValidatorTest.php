<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Unit;

use JsonLinesImporter\Validation\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SchemaValidatorTest extends TestCase
{
    public static function exampleFiles(): iterable
    {
        foreach (['product_jsonl_with_i18n', 'product_jsonl_not_i18n', 'product_jsonl_not_variants'] as $name) {
            yield $name => [\dirname(__DIR__).'/fixtures/'.$name.'.jsonl'];
        }
    }

    #[Test]
    #[DataProvider('exampleFiles')]
    public function theExamplesOfTheStructureDocumentAreValid(string $path): void
    {
        self::assertSame([], (new SchemaValidator())->validate(json_decode((string) file_get_contents($path))));
    }

    #[Test]
    public function twoDefaultVariantsAreRefused(): void
    {
        // minContains (draft 2020-12): a draft 6/7 validator would let this line through.
        $line = json_decode('{"ref":"X","variants":[{"ref":"A","default":true},{"ref":"B","default":true}]}');

        $errors = (new SchemaValidator())->validate($line);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('/variants', $errors[0]);
    }

    #[Test]
    public function anUnknownKeyAndAMissingRefAreRefused(): void
    {
        $validator = new SchemaValidator();

        self::assertNotSame([], $validator->validate(json_decode('{"ref":"X","colour":"red"}')));
        self::assertNotSame([], $validator->validate(json_decode('{"visible":true}')));
    }
}
