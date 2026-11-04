<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Unit;

use JsonLinesImporter\Dto\ImportParameters;
use JsonLinesImporter\Service\ProductLineFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductLineFactoryTest extends TestCase
{
    #[Test]
    public function theTitleIsKeptForTheCreationWhenItsBoxIsUnticked(): void
    {
        $parameters = new ImportParameters();
        $line = (new ProductLineFactory())->create(['ref' => 'A', 'title' => 'Lamp', 'chapo' => 'Desk lamp'], 'fr_FR', $parameters);

        self::assertFalse($parameters->isKeySelected('title'), 'the title box is unticked by default');
        self::assertFalse($line->titleSelected);
        self::assertSame('Lamp', $line->i18n->get('fr_FR', 'title'));
        self::assertSame('Desk lamp', $line->i18n->get('fr_FR', 'chapo'));
    }

    #[Test]
    public function anUntickedKeyBecomesAbsent(): void
    {
        $parameters = new ImportParameters(selectedKeys: ['images']);
        $line = (new ProductLineFactory())->create(['ref' => 'A', 'categories' => [['title' => 'C']], 'images' => null, 'chapo' => 'x'], 'fr_FR', $parameters);

        self::assertTrue($line->categories->isAbsent());
        self::assertTrue($line->images->isNull(), 'null is kept: it empties the collection');
        self::assertFalse($line->i18n->has('fr_FR', 'chapo'));
    }

    #[Test]
    public function variantsKeepTheirRefsAndCombination(): void
    {
        $line = (new ProductLineFactory())->create([
            'ref' => 'A',
            'price' => 10,
            'variants' => [
                ['ref' => 'A-1', 'default' => true, 'combination' => [['attribute' => ['title' => 'Colour'], 'value' => ['title' => 'Black']]]],
                ['ref' => 'A-2', 'price' => 12, 'images' => ['front']],
            ],
        ], 'fr_FR', new ImportParameters());

        $variants = $line->variants->value;

        self::assertCount(2, $variants);
        self::assertSame('A-1', $variants[0]->ref);
        self::assertTrue($variants[0]->default);
        self::assertSame('Colour', $variants[0]->combination[0]->attribute->matchingTitle);
        self::assertSame(10.0, $variants[0]->sale->withFallback($line->sale)->price->value, 'a field absent from a variant takes the root one');
        self::assertSame(12.0, $variants[1]->sale->withFallback($line->sale)->price->value);
        self::assertSame(['front'], $variants[1]->images->value);
    }

    #[Test]
    public function anUntickedSaleKeyIsLeftUnchangedOnTheVariantsToo(): void
    {
        $parameters = new ImportParameters(selectedKeys: ['variants', 'price']);
        $line = (new ProductLineFactory())->create([
            'ref' => 'A',
            'quantity' => 5,
            'variants' => [['ref' => 'A-1', 'quantity' => 3, 'price' => 12, 'ean_code' => '2000000000008']],
        ], 'fr_FR', $parameters);

        $sale = $line->variants->value[0]->sale->withFallback($line->sale);

        self::assertTrue($sale->quantity->isAbsent());
        self::assertTrue($sale->eanCode->isAbsent());
        self::assertSame(12.0, $sale->price->value);
    }

    #[Test]
    public function rootContentsStayWhenNoLocaleIsUnticked(): void
    {
        $parameters = new ImportParameters(selectedLocales: ['fr_FR', 'en_US']);
        $line = (new ProductLineFactory())->create(['ref' => 'A', 'url' => 'https://old.example.com/a/', 'i18n' => ['de_DE' => ['url' => 'x']]], 'fr_FR', $parameters);

        self::assertSame(['fr_FR'], $line->urls->locales());
    }

    #[Test]
    public function theSourceOfALanguageWinsOverTheRootOneForThisLanguage(): void
    {
        $line = (new ProductLineFactory())->create([
            'ref' => 'A',
            'images' => [['source' => 'front.jpg', 'i18n' => ['fr_FR' => ['source' => 'face.jpg', 'title' => 'Face']]]],
        ], 'fr_FR', new ImportParameters());

        $image = $line->images->value[0];

        self::assertSame('front.jpg', $image->identity);
        self::assertSame(['fr_FR' => 'face.jpg'], $image->localeSources);
        self::assertSame(['fr_FR' => 'face.jpg', 'en_US' => 'front.jpg', 'de_DE' => 'front.jpg'], $image->filesByLocale(['fr_FR', 'en_US', 'de_DE']));
        self::assertFalse($image->i18n->hasField('source'), 'a source is not a text: the HTML sanitizer never sees it');
    }

    #[Test]
    public function anImageWithoutRootSourceIsIdentifiedByItsFirstLanguageSource(): void
    {
        $line = (new ProductLineFactory())->create([
            'ref' => 'A',
            'images' => [['i18n' => ['en_US' => ['source' => 'diagram.jpg'], 'fr_FR' => ['source' => 'schema.jpg']]]],
        ], 'fr_FR', new ImportParameters(selectedLocales: ['fr_FR']));

        $image = $line->images->value[0];

        self::assertNull($image->source);
        self::assertSame('diagram.jpg', $image->identity, 'read before the locale selection');
        self::assertSame(['fr_FR' => 'schema.jpg'], $image->localeSources);
        self::assertSame(['fr_FR' => 'schema.jpg'], $image->filesByLocale(['fr_FR', 'en_US']));
    }

    #[Test]
    public function theRootSourceOfAnImageGoesToEveryActiveLanguageEvenAnUntickedOne(): void
    {
        $line = (new ProductLineFactory())->create([
            'ref' => 'A',
            'images' => [['source' => 'front.jpg', 'i18n' => ['en_US' => ['source' => 'front-en.jpg']]]],
        ], 'fr_FR', new ImportParameters(selectedLocales: ['fr_FR']));

        self::assertSame(['fr_FR' => 'front.jpg', 'en_US' => 'front.jpg'], $line->images->value[0]->filesByLocale(['fr_FR', 'en_US']));
    }

    #[Test]
    public function aDocumentKeepsOneFile(): void
    {
        $line = (new ProductLineFactory())->create([
            'ref' => 'A',
            'documents' => [['source' => 'manual.pdf', 'i18n' => ['fr_FR' => ['title' => 'Notice']]]],
        ], 'fr_FR', new ImportParameters());

        $document = $line->documents->value[0];

        self::assertSame('manual.pdf', $document->identity);
        self::assertSame([], $document->localeSources);
    }

    #[Test]
    public function aVideoKeepsItsVisibilityAndTextsButNotItsFormerGroup(): void
    {
        $line = (new ProductLineFactory())->create([
            'ref' => 'A',
            'videos' => [
                ['source' => ' https://youtu.be/aBcDeFgHi01 ', 'visible' => false, 'position' => 2, 'title' => 'Demo', 'alt' => 'Demo of the lamp', 'group' => 'Block group', 'i18n' => ['en_US' => ['description' => 'In use']]],
                ['source' => 'clip.mp4'],
            ],
        ], 'fr_FR', new ImportParameters());

        [$first, $second] = $line->videos->value;

        self::assertSame(' https://youtu.be/aBcDeFgHi01 ', $first->source);
        self::assertFalse($first->visible->value);
        self::assertSame(2, $first->position);
        self::assertSame('Demo of the lamp', $first->i18n->get('fr_FR', 'alt'));
        self::assertSame('In use', $first->i18n->get('en_US', 'description'));
        self::assertFalse($first->i18n->hasField('group'));
        self::assertTrue($second->visible->isAbsent());
        self::assertNull($second->position);
    }
}
