<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Integration;

use JsonLinesImporter\Dto\ImportParameters;
use JsonLinesImporter\Model\JsonlImport;
use JsonLinesImporter\Model\JsonlImportLineQuery;
use JsonLinesImporter\Model\JsonlImportMappingQuery;
use JsonLinesImporter\Service\AnalysisService;
use JsonLinesImporter\Service\ImportManager;
use JsonLinesImporter\Service\ImportRunner;
use PHPUnit\Framework\Attributes\Test;
use Thelia\Model\Category;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Model\RewritingUrlQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * A file goes through the analysis and the batches of the runner: the product is created, a replay
 * creates nothing more, and a variant that leaves the file is hidden while the others keep their id.
 *
 * Prerequisites: a shop test database where the module is activated. No network: the lines carry no media.
 */
final class ImportRoundTripTest extends IntegrationTestCase
{
    private const REF = 'JLI-ROUND-TRIP';

    // Each line is written in its own transaction: a surrounding one would swallow its rollbacks.
    protected bool $useTransaction = false;

    private ?int $categoryId = null;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (null === ModuleQuery::create()->filterByCode('JsonLinesImporter')->filterByActivate(1)->findOne()) {
            self::markTestSkipped('The JsonLinesImporter module is not active in the test database.');
        }

        $this->cleanUp();

        $category = (new Category())->setParent(0)->setVisible(0);
        $category->setLocale('en_US')->setTitle('JLI round trip')->setLocale('fr_FR')->setTitle('JLI round trip');
        $category->save();
        $this->categoryId = (int) $category->getId();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        CategoryQuery::create()->filterById($this->categoryId)->delete();

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    #[Test]
    public function aFileIsImportedThenReplayedWithoutDuplicates(): void
    {
        $line = [
            'ref' => self::REF,
            'title' => 'Round trip',
            'price' => 10,
            'categories' => [['title' => 'JLI round trip']],
            'url' => 'https://old.example.com/products/round-trip/',
            'variants' => [
                ['ref' => self::REF.'-A', 'price' => 10, 'default' => true],
                ['ref' => self::REF.'-B', 'price' => 12, 'prices' => [['currency' => 'USD', 'price' => 15]]],
            ],
        ];

        $first = $this->import([$line]);
        self::assertSame(1, (int) $first->getCreatedCount());
        self::assertSame(0, (int) $first->getErrorCount());

        $product = ProductQuery::create()->findOneByRef(self::REF);
        self::assertNotNull($product);
        self::assertSame('Round trip', $product->setLocale(\Thelia\Model\Lang::getDefaultLanguage()->getLocale())->getTitle(), 'the title is used on creation although its box is unticked');

        $saleElements = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->orderByRef()->find()->getData();
        self::assertCount(2, $saleElements);
        $idOfA = (int) $saleElements[0]->getId();
        self::assertTrue((bool) $saleElements[0]->getIsDefault());

        self::assertSame(2, RewritingUrlQuery::create()->filterByUrl(['products/round-trip/', 'products/round-trip'])->filterByRedirected(null, \Propel\Runtime\ActiveQuery\Criteria::ISNOTNULL)->count());

        $line['variants'] = [['ref' => self::REF.'-A', 'price' => 11]];
        $second = $this->import([$line]);
        self::assertSame(1, (int) $second->getUpdatedCount());

        $a = ProductSaleElementsQuery::create()->findOneByRef(self::REF.'-A');
        $b = ProductSaleElementsQuery::create()->findOneByRef(self::REF.'-B');
        self::assertSame($idOfA, (int) $a->getId(), 'a matched variant keeps its id');
        self::assertFalse((bool) $b->getVisible(), 'a variant missing from the file is hidden, not deleted');
        self::assertSame(2, RewritingUrlQuery::create()->filterByUrl(['products/round-trip/', 'products/round-trip'])->count(), 'a replay adds no redirection');
    }

    #[Test]
    public function anAddressOfTheShopNeverBecomesARedirection(): void
    {
        $import = $this->import([['ref' => self::REF, 'title' => 'Round trip', 'price' => 10, 'url' => '/admin/login']]);

        self::assertSame(1, (int) $import->getCreatedCount());
        self::assertSame(0, RewritingUrlQuery::create()->filterByUrl('admin/login')->count());

        $line = JsonlImportLineQuery::create()->filterByImportId($import->getId())->findOne();
        self::assertContains('Redirection /admin/login not created: the address is a page of the shop', $line->getWarningList());
    }

    /** @param list<array<string, mixed>> $lines */
    private function import(array $lines): JsonlImport
    {
        $container = static::getContainer();
        $path = (string) tempnam(sys_get_temp_dir(), 'jli-test-');
        file_put_contents($path, implode("\n", array_map(static fn (array $line): string => json_encode($line, \JSON_THROW_ON_ERROR), $lines))."\n");
        $this->files[] = $path;

        $import = $container->get(ImportManager::class)->create($path, 'round-trip.jsonl', 'test', new ImportParameters(fallbackCategoryId: $this->categoryId));

        while (!$container->get(AnalysisService::class)->analyseBatch($import, ImportRunner::batchDeadline())) {
        }

        self::assertSame(\count($lines), (int) $import->getValidCount(), 'every line is valid');

        $runner = $container->get(ImportRunner::class);
        $runner->start($import);

        while (!$runner->runBatch($import, ImportRunner::batchDeadline())) {
        }

        $import->reload();

        return $import;
    }

    private function cleanUp(): void
    {
        $product = ProductQuery::create()->findOneByRef(self::REF);

        if (null !== $product) {
            RewritingUrlQuery::create()->filterByView('product')->filterByViewId((string) $product->getId())->filterByRedirected(null, \Propel\Runtime\ActiveQuery\Criteria::ISNOTNULL)->update(['Redirected' => null]);
            RewritingUrlQuery::create()->filterByUrl(['products/round-trip/', 'products/round-trip', 'admin/login'])->filterByView('product')->delete();
            $product->delete();
        }

        foreach (\JsonLinesImporter\Model\JsonlImportQuery::create()->filterByOriginalName('round-trip.jsonl')->find() as $import) {
            $import->delete();
        }

        JsonlImportMappingQuery::create()->filterBySourceTitle('jli round trip')->delete();
    }
}
