<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Integration;

use JsonLinesImporter\Dto\ImportParameters;
use JsonLinesImporter\Service\LineReport;
use JsonLinesImporter\Service\ProductLineFactory;
use JsonLinesImporter\Service\Writer\ProductWriter;
use PHPUnit\Framework\Attributes\Test;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\AccessoryQuery;
use Thelia\Model\Lang;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductAssociationType;
use Thelia\Model\ProductQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The accessories of a line are written as links of the accessory type, and the links of the other
 * types of the product (cross-selling, up-selling) are left alone.
 *
 * Prerequisites: a shop test database where the module is activated.
 */
final class AccessoryWriteTest extends IntegrationTestCase
{
    private const PREFIX = 'JLI-ACC-';

    // The second pass opens its own transaction on the write connection.
    protected bool $useTransaction = false;

    private FixtureFactory $fixtures;

    /** @var array<string, Product> */
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (null === ModuleQuery::create()->filterByCode('JsonLinesImporter')->filterByActivate(1)->findOne()) {
            self::markTestSkipped('The JsonLinesImporter module is not active in the test database.');
        }

        $this->cleanUp();

        $this->fixtures = $this->createFixtureFactory();

        // An accessory links two products: no sale element is needed.
        foreach (['MAIN', 'A', 'B', 'C'] as $name) {
            $this->products[$name] = (new Product())->setRef(self::PREFIX.$name);
            $this->products[$name]->save();
        }
    }

    protected function tearDown(): void
    {
        $this->cleanUp();

        parent::tearDown();
    }

    #[Test]
    public function theAccessoriesAreWrittenWithTheirTypeAndTheOtherRelationsAreKept(): void
    {
        $this->fixtures->accessory($this->products['MAIN'], $this->products['B'], 5);
        $this->fixtures->association($this->products['MAIN'], $this->products['C'], ProductAssociationType::CODE_CROSS_SELLING, 1);

        $report = $this->writeAccessories([['ref' => self::PREFIX.'A', 'position' => 2], ['ref' => self::PREFIX.'B', 'position' => 1]]);

        self::assertSame([], $report->warnings());
        self::assertSame(['A' => 2, 'B' => 1], $this->links(ProductAssociationType::CODE_ACCESSORY));
        self::assertSame(['C' => 1], $this->links(ProductAssociationType::CODE_CROSS_SELLING), 'a cross-selling link is not an accessory of the file');

        $report = $this->writeAccessories([['ref' => self::PREFIX.'A']]);

        self::assertSame([], $report->warnings());
        self::assertSame(['A' => 1], $this->links(ProductAssociationType::CODE_ACCESSORY), 'an accessory missing from the file is removed');
        self::assertSame(['C' => 1], $this->links(ProductAssociationType::CODE_CROSS_SELLING));
    }

    /** @param list<array<string, mixed>> $accessories */
    private function writeAccessories(array $accessories): LineReport
    {
        $container = static::getContainer();
        $line = $container->get(ProductLineFactory::class)->create(
            ['ref' => self::PREFIX.'MAIN', 'accessories' => $accessories],
            Lang::getDefaultLanguage()->getLocale(),
            new ImportParameters(),
        );

        $report = new LineReport();
        $container->get(ProductWriter::class)->writeAccessories($line, $report);

        return $report;
    }

    /** @return array<string, int> position by product name, for the links of the main product of a type */
    private function links(string $typeCode): array
    {
        $names = array_flip(array_map(static fn (Product $product): int => (int) $product->getId(), $this->products));
        $links = [];

        foreach (AccessoryQuery::create()
            ->filterByProductId($this->products['MAIN']->getId())
            ->filterByTypeId($this->fixtures->productAssociationType($typeCode)->getId())
            ->orderByAccessory()
            ->find() as $link) {
            $links[$names[(int) $link->getAccessory()]] = (int) $link->getPosition();
        }

        return $links;
    }

    private function cleanUp(): void
    {
        ProductQuery::create()->filterByRef(self::PREFIX.'%', Criteria::LIKE)->find()->delete();
    }
}
