<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Unit;

use JsonLinesImporter\Service\DataCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DataCatalogTest extends TestCase
{
    #[Test]
    public function aSaleKeyFoundOnlyInTheVariantsHasItsBox(): void
    {
        $keys = (new DataCatalog())->keysOf([
            'ref' => 'A',
            'variants' => [['ref' => 'A-1', 'quantity' => 3, 'combination' => []], ['ref' => 'A-2', 'price' => 12]],
        ]);

        sort($keys);

        self::assertSame(['price', 'quantity', 'variants'], $keys);
    }
}
