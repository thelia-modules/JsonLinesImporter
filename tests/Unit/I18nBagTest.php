<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Unit;

use JsonLinesImporter\Dto\I18nBag;
use JsonLinesImporter\Dto\Label;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class I18nBagTest extends TestCase
{
    #[Test]
    public function rootKeysTargetTheDefaultLocaleAndTheI18nBlockWins(): void
    {
        $bag = I18nBag::fromNode([
            'title' => 'Root title',
            'chapo' => 'Root chapo',
            'i18n' => [
                'fr_FR' => ['title' => 'Bloc'],
                'en_US' => ['title' => 'Block', 'description' => null],
            ],
        ], ['title', 'chapo', 'description'], 'fr_FR');

        self::assertSame('Bloc', $bag->get('fr_FR', 'title'));
        self::assertSame('Root chapo', $bag->get('fr_FR', 'chapo'));
        self::assertSame('Block', $bag->get('en_US', 'title'));
        self::assertTrue($bag->has('en_US', 'description'), 'a null value is kept: it empties the field');
        self::assertFalse($bag->has('en_US', 'chapo'), 'a field absent from a locale is left untouched');
    }

    #[Test]
    public function aLocaleOutsideTheSelectionIsDropped(): void
    {
        $bag = I18nBag::fromNode(['i18n' => ['fr_FR' => ['title' => 'A'], 'de_DE' => ['title' => 'B']]], ['title'], 'fr_FR', ['fr_FR', 'en_US']);

        self::assertSame(['fr_FR'], $bag->locales());
    }

    #[Test]
    public function theMatchingTitleIsTheDefaultLocaleOneElseTheFirstLocale(): void
    {
        self::assertSame('Matière', Label::fromNode(['i18n' => ['en_US' => ['title' => 'Material'], 'fr_FR' => ['title' => 'Matière']]], 'fr_FR')->matchingTitle);
        self::assertSame('Material', Label::fromNode(['i18n' => ['en_US' => ['title' => 'Material']]], 'fr_FR')->matchingTitle);
        self::assertSame('intérieur', Label::fromTitle('  Intérieur ', 'fr_FR')->normalizedTitle());
    }
}
