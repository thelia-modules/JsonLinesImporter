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
use Thelia\Model\Map\ProductI18nTableMap;
use Thelia\Model\Map\ProductImageI18nTableMap;
use Thelia\Model\ModuleQuery;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The HTML of a file comes from its author, not from an administrator of the shop, and the front theme
 * prints it as is: unless the file is trusted at step 3, what could run in a browser is removed.
 */
final class HtmlSanitizingTest extends IntegrationTestCase
{
    private const REF = 'JLI-HTML';

    private const UNSAFE_CHAPO = '<p class="lead">Desk lamp<img src="x" onerror="alert(1)"></p>';

    private const UNSAFE_DESCRIPTION = '<p>Arm <a href="javascript:alert(1)">details</a></p><script>alert(1)</script>';

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
        $category->setLocale(Lang::getDefaultLanguage()->getLocale())->setTitle('JLI html');
        $category->save();
        $this->categoryId = (int) $category->getId();

        $this->directoryName = 'jli-html-'.bin2hex(random_bytes(4));
        $this->directory = JsonLinesImporter::getMediaSourceRoot().$this->directoryName;
        mkdir($this->directory, 0o770, true);
        // A real PNG (1x1).
        file_put_contents($this->directory.'/front.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
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
    public function theHtmlOfTheFileIsCleanedOfWhatCouldRun(): void
    {
        $report = $this->write(new ImportParameters(fallbackCategoryId: $this->categoryId, imagesDirectory: $this->directoryName));

        $product = $this->product();
        self::assertStringNotContainsString('onerror', $product['chapo']);
        self::assertStringContainsString('Desk lamp', $product['chapo']);
        self::assertStringContainsString('class="lead"', $product['chapo'], 'safe markup stays');
        self::assertStringNotContainsString('javascript:', $product['description']);
        self::assertStringNotContainsString('<script', $product['description']);
        self::assertStringContainsString('details', $product['description']);
        self::assertSame('<p>Safe <strong>text</strong></p>', $product['postscriptum'], 'safe HTML is written unchanged');
        self::assertStringNotContainsString('onerror', $this->imageDescription());

        self::assertCount(3, $report->warnings(), 'one warning per field cleaned: chapo, description, image description');
    }

    #[Test]
    public function aTrustedFileIsWrittenAsIs(): void
    {
        $report = $this->write(new ImportParameters(fallbackCategoryId: $this->categoryId, imagesDirectory: $this->directoryName, trustHtml: true));

        $product = $this->product();
        self::assertSame(self::UNSAFE_CHAPO, $product['chapo']);
        self::assertSame(self::UNSAFE_DESCRIPTION, $product['description']);
        self::assertSame([], $report->warnings());
    }

    #[Test]
    public function aLongDescriptionIsNotCut(): void
    {
        $description = str_repeat('<p>An articulated arm and a touch dimmer.</p>', 1000);

        $this->write(new ImportParameters(fallbackCategoryId: $this->categoryId), ['description' => $description]);

        self::assertSame($description, $this->product()['description']);
    }

    /** @param array<string, mixed> $override */
    private function write(ImportParameters $parameters, array $override = []): LineReport
    {
        $container = static::getContainer();
        $line = $container->get(ProductLineFactory::class)->create(
            [
                'ref' => self::REF,
                'title' => 'Html',
                'price' => 10,
                'chapo' => self::UNSAFE_CHAPO,
                'description' => self::UNSAFE_DESCRIPTION,
                'postscriptum' => '<p>Safe <strong>text</strong></p>',
                'images' => [['source' => 'front.png', 'description' => '<img src="x" onerror="alert(1)">Front']],
                ...$override,
            ],
            Lang::getDefaultLanguage()->getLocale(),
            $parameters,
        );

        $report = new LineReport();
        $container->get(ProductWriter::class)->write($line, $parameters, $report);

        return $report;
    }

    /** @return array{chapo: string, description: string, postscriptum: string} */
    private function product(): array
    {
        ProductI18nTableMap::clearInstancePool();
        $product = ProductQuery::create()->findOneByRef(self::REF)->setLocale(Lang::getDefaultLanguage()->getLocale());

        return [
            'chapo' => (string) $product->getChapo(),
            'description' => (string) $product->getDescription(),
            'postscriptum' => (string) $product->getPostscriptum(),
        ];
    }

    private function imageDescription(): string
    {
        ProductImageI18nTableMap::clearInstancePool();
        $image = ProductImageQuery::create()->filterByProductId(ProductQuery::create()->findOneByRef(self::REF)->getId())->findOne();

        return (string) $image->setLocale(Lang::getDefaultLanguage()->getLocale())->getDescription();
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
