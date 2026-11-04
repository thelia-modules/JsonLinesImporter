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

    #[Test]
    public function aVideoCarriesTheTextsOfAProductVideoAndStillAcceptsItsFormerGroup(): void
    {
        $line = json_decode('{"ref":"X","videos":[{"source":"https://vimeo.com/123456789","visible":false,"position":2,"title":"Demo","alt":"Demo of the lamp","chapo":"c","description":"d","postscriptum":"p","group":"Former block group","i18n":{"en_US":{"title":"Demo","alt":"Lamp demo","group":"Former"}}}]}');

        self::assertSame([], (new SchemaValidator())->validate($line));
    }

    #[Test]
    public function anImageTakesItsFileFromTheRootOrFromALanguage(): void
    {
        $validator = new SchemaValidator();

        self::assertSame([], $validator->validate(json_decode('{"ref":"X","images":[{"source":"a.jpg","i18n":{"fr_FR":{"source":"a-fr.jpg","title":"A"}}}]}')));
        self::assertSame([], $validator->validate(json_decode('{"ref":"X","images":[{"i18n":{"en_US":{"title":"A"},"fr_FR":{"source":"a-fr.jpg"}}}]}')));
    }

    #[Test]
    public function anImageWithoutAnySourceIsRefused(): void
    {
        $validator = new SchemaValidator();

        self::assertNotSame([], $validator->validate(json_decode('{"ref":"X","images":[{"title":"A"}]}')));
        self::assertNotSame([], $validator->validate(json_decode('{"ref":"X","images":[{"i18n":{"fr_FR":{"title":"A"}}}]}')));
    }

    #[Test]
    public function theFileOfADocumentIsNotTranslated(): void
    {
        $validator = new SchemaValidator();

        self::assertNotSame([], $validator->validate(json_decode('{"ref":"X","documents":[{"source":"a.pdf","i18n":{"fr_FR":{"source":"a-fr.pdf"}}}]}')));
        self::assertNotSame([], $validator->validate(json_decode('{"ref":"X","documents":[{"i18n":{"fr_FR":{"title":"A"}}}]}')));
    }

    #[Test]
    public function anUnknownKeyOfAVideoIsRefused(): void
    {
        $errors = (new SchemaValidator())->validate(json_decode('{"ref":"X","videos":[{"source":"https://youtu.be/aBcDeFgHi01","thumbnail":"front"}]}'));

        self::assertNotSame([], $errors);
        self::assertStringContainsString('/videos', $errors[0]);
    }
}
