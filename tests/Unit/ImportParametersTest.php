<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Unit;

use JsonLinesImporter\Dto\ImportParameters;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImportParametersTest extends TestCase
{
    #[Test]
    public function everyKeyButTheTitleIsSelectedByDefault(): void
    {
        $parameters = new ImportParameters();

        self::assertTrue($parameters->isKeySelected('description'));
        self::assertFalse($parameters->isKeySelected('title'));
        self::assertTrue($parameters->isLocaleSelected('fr_FR'));
    }

    #[Test]
    public function itSurvivesARoundTripThroughItsArray(): void
    {
        $parameters = new ImportParameters(
            selectedKeys: ['title', 'images'],
            createProducts: false,
            videosDirectory: 'videos',
            trustHtml: true,
            excludedLines: [3, 7],
        );

        $copy = ImportParameters::fromArray($parameters->toArray());

        self::assertEquals($parameters, $copy);
        self::assertTrue($copy->isLineExcluded(7));
        self::assertSame('videos', $copy->with(['step' => 4])->videosDirectory);
        self::assertTrue($copy->trustHtml);
        self::assertFalse((new ImportParameters())->trustHtml, 'the HTML of a file is not trusted by default');
    }

    #[Test]
    public function theVideoDestinationOfAnOlderImportIsIgnored(): void
    {
        $parameters = ImportParameters::fromArray(['video_destination' => 'feature', 'video_feature_id' => 12, 'step' => 3]);

        self::assertSame(3, $parameters->step);
        self::assertArrayNotHasKey('video_destination', $parameters->toArray());
        self::assertArrayNotHasKey('video_feature_id', $parameters->toArray());
    }
}
