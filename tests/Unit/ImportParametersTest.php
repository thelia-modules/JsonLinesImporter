<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Unit;

use JsonLinesImporter\Dto\ImportParameters;
use JsonLinesImporter\Enum\VideoDestination;
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
            videoDestination: VideoDestination::FEATURE,
            videoFeatureId: 12,
            excludedLines: [3, 7],
        );

        $copy = ImportParameters::fromArray($parameters->toArray());

        self::assertEquals($parameters, $copy);
        self::assertTrue($copy->isLineExcluded(7));
        self::assertSame(VideoDestination::FEATURE, $copy->with(['step' => 4])->videoDestination);
    }
}
