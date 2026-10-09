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
    public function rootContentsStayWhenNoLocaleIsUnticked(): void
    {
        $parameters = new ImportParameters(selectedLocales: ['fr_FR', 'en_US']);
        $line = (new ProductLineFactory())->create(['ref' => 'A', 'url' => 'https://old.example.com/a/', 'i18n' => ['de_DE' => ['url' => 'x']]], 'fr_FR', $parameters);

        self::assertSame(['fr_FR'], $line->urls->locales());
    }
}
