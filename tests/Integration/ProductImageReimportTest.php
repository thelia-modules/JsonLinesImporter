<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Integration;

use JsonLinesImporter\Dto\ImportParameters;
use JsonLinesImporter\JsonLinesImporter;
use JsonLinesImporter\Model\JsonlImportMediaQuery;
use JsonLinesImporter\Service\LineReport;
use JsonLinesImporter\Service\ProductLineFactory;
use JsonLinesImporter\Service\Writer\ProductWriter;
use PHPUnit\Framework\Attributes\Test;
use Thelia\Model\Category;
use Thelia\Model\CategoryQuery;
use Thelia\Model\Lang;
use Thelia\Model\Map\ProductImageTableMap;
use Thelia\Model\ModuleQuery;
use Thelia\Model\ProductImage;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * Deleting an image closes the gap it leaves in the sequence of the product: the images that leave the
 * line are removed before the positions of the others are written.
 */
final class ProductImageReimportTest extends IntegrationTestCase
{
    private const REF = 'JLI-IMAGE-REIMPORT';

    // Each line is written in its own transaction: a surrounding one would swallow its rollbacks.
    protected bool $useTransaction = false;

    private ?int $categoryId = null;

    private string $directory;

    private string $directoryName;

    protected function setUp(): void
    {
        parent::setUp();

        if (null === ModuleQuery::create()->filterByCode('JsonLinesImporter')->filterByActivate(1)->findOne()) {
            self::markTestSkipped('The JsonLinesImporter module is not active in the test database.');
        }

        $this->cleanUp();

        $category = (new Category())->setParent(0)->setVisible(0);
        $category->setLocale(Lang::getDefaultLanguage()->getLocale())->setTitle('JLI image reimport');
        $category->save();
        $this->categoryId = (int) $category->getId();

        // Media read from the server lie inside the import root of the shop.
        $this->directoryName = 'jli-image-'.bin2hex(random_bytes(4));
        $this->directory = JsonLinesImporter::getMediaSourceRoot().$this->directoryName;
        mkdir($this->directory, 0o770, true);
        // A real PNG (1x1).
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        foreach (['alpha', 'bravo', 'charlie', 'delta'] as $name) {
            file_put_contents($this->directory.'/'.$name.'.png', $png);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        CategoryQuery::create()->filterById($this->categoryId)->delete();

        array_map('unlink', glob($this->directory.'/*') ?: []);
        @rmdir($this->directory);

        parent::tearDown();
    }

    #[Test]
    public function anImageLeavingTheLineDoesNotShiftThePositionsOfTheOthers(): void
    {
        $this->write(['alpha.png', 'bravo.png', 'charlie.png']);
        self::assertSame(['alpha' => 1, 'bravo' => 2, 'charlie' => 3], $this->positions());

        $this->write(['bravo.png', 'charlie.png', 'delta.png']);
        self::assertSame(['bravo' => 1, 'charlie' => 2, 'delta' => 3], $this->positions());
    }

    #[Test]
    public function aKeptImageGetsItsPositionEvenWhenARemovalMovedItThere(): void
    {
        $this->write(['alpha.png', 'bravo.png', 'charlie.png']);

        // Removing alpha moves bravo from 2 to 1 in the database; the line wants it at 2 again.
        $this->write(['delta.png', 'bravo.png']);
        self::assertSame(['delta' => 1, 'bravo' => 2], $this->positions());
    }

    /** @param list<string> $sources */
    private function write(array $sources): void
    {
        $container = static::getContainer();
        $parameters = new ImportParameters(fallbackCategoryId: $this->categoryId, imagesDirectory: $this->directoryName);
        $line = $container->get(ProductLineFactory::class)->create(
            [
                'ref' => self::REF,
                'title' => 'Image reimport',
                'price' => 10,
                'images' => array_map(static fn (string $source): array => ['source' => $source], $sources),
            ],
            Lang::getDefaultLanguage()->getLocale(),
            $parameters,
        );

        $report = new LineReport();
        $container->get(ProductWriter::class)->write($line, $parameters, $report);
        self::assertSame([], $report->warnings());
    }

    /** @return array<string, int> name of the source file => position */
    private function positions(): array
    {
        ProductImageTableMap::clearInstancePool();
        $positions = [];

        /** @var ProductImage $image */
        foreach (ProductImageQuery::create()->filterByProductId($this->productId())->orderByPosition()->find() as $image) {
            $positions[explode('-', (string) $image->getFile())[0]] = (int) $image->getPosition();
        }

        return $positions;
    }

    private function productId(): int
    {
        return (int) ProductQuery::create()->findOneByRef(self::REF)?->getId();
    }

    private function cleanUp(): void
    {
        $product = ProductQuery::create()->findOneByRef(self::REF);

        if (null === $product) {
            return;
        }

        foreach (ProductImageQuery::create()->filterByProductId($product->getId())->find() as $image) {
            @unlink($image->getUploadDir().'/'.$image->getFile());
        }

        JsonlImportMediaQuery::create()->filterByProductId($product->getId())->delete();
        $product->delete();
    }
}
