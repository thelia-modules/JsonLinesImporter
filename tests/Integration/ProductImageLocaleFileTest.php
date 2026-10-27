<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Integration;

use JsonLinesImporter\Dto\ImportParameters;
use JsonLinesImporter\JsonLinesImporter;
use JsonLinesImporter\Model\JsonlImportMediaQuery;
use JsonLinesImporter\Service\LineReport;
use JsonLinesImporter\Service\ProductLineFactory;
use JsonLinesImporter\Service\ShopContext;
use JsonLinesImporter\Service\Writer\ProductWriter;
use PHPUnit\Framework\Attributes\Test;
use Thelia\Model\Category;
use Thelia\Model\CategoryQuery;
use Thelia\Model\Lang;
use Thelia\Model\Map\ProductImageI18nTableMap;
use Thelia\Model\Map\ProductImageTableMap;
use Thelia\Model\ModuleQuery;
use Thelia\Model\ProductImage;
use Thelia\Model\ProductImageI18nQuery;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The file of an image is translated (Thelia 3.3): the root source is the file of every active language,
 * the source of an i18n block wins over it for its language.
 */
final class ProductImageLocaleFileTest extends IntegrationTestCase
{
    private const REF = 'JLI-IMAGE-LOCALE-FILE';

    // Each line is written in its own transaction: a surrounding one would swallow its rollbacks.
    protected bool $useTransaction = false;

    private ?int $categoryId = null;

    private string $directory;

    private string $directoryName;

    /** @var list<string> */
    private array $activeLocales;

    private string $otherLocale;

    protected function setUp(): void
    {
        parent::setUp();

        if (null === ModuleQuery::create()->filterByCode('JsonLinesImporter')->filterByActivate(1)->findOne()) {
            self::markTestSkipped('The JsonLinesImporter module is not active in the test database.');
        }

        $this->activeLocales = (new ShopContext())->activeLocales();
        $others = array_values(array_diff($this->activeLocales, [Lang::getDefaultLanguage()->getLocale()]));

        if ([] === $others) {
            self::markTestSkipped('The test database needs two active languages.');
        }

        $this->otherLocale = $others[0];

        $this->cleanUp();

        $category = (new Category())->setParent(0)->setVisible(0);
        $category->setLocale(Lang::getDefaultLanguage()->getLocale())->setTitle('JLI image locale file');
        $category->save();
        $this->categoryId = (int) $category->getId();

        $this->directoryName = 'jli-locale-'.bin2hex(random_bytes(4));
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
    public function theRootSourceIsTheFileOfEveryActiveLanguage(): void
    {
        $this->write([['source' => 'alpha.png']]);

        $files = $this->filesByLocale();

        self::assertSame($this->activeLocales, array_keys(array_filter($files)), 'one translation with a file per active language');
        self::assertCount(1, array_unique($files), 'the languages share one file');
        self::assertStringStartsWith('alpha-', reset($files));
        self::assertSame(1, \count($this->storedFilesOnDisk()));
    }

    #[Test]
    public function theSourceOfALanguageWinsOverTheRootOne(): void
    {
        $this->write([['source' => 'alpha.png', 'i18n' => [$this->otherLocale => ['source' => 'bravo.png']]]]);

        $files = $this->filesByLocale();

        self::assertStringStartsWith('bravo-', $files[$this->otherLocale]);

        foreach (array_diff($this->activeLocales, [$this->otherLocale]) as $locale) {
            self::assertStringStartsWith('alpha-', $files[$locale]);
        }

        self::assertCount(2, $this->storedFilesOnDisk());
    }

    #[Test]
    public function anImageMayHaveTheFileOfOneLanguageOnly(): void
    {
        $this->write([['_key' => 'diagram', 'i18n' => [$this->otherLocale => ['source' => 'charlie.png', 'title' => 'Diagram']]]]);

        $files = array_filter($this->filesByLocale());

        self::assertSame([$this->otherLocale], array_keys($files));
        self::assertStringStartsWith('charlie-', $files[$this->otherLocale]);
    }

    #[Test]
    public function replayingTheLineKeepsTheFiles(): void
    {
        $line = [['source' => 'alpha.png', 'i18n' => [$this->otherLocale => ['source' => 'bravo.png']]]];

        $this->write($line);
        $files = $this->filesByLocale();
        $onDisk = $this->storedFilesOnDisk();

        $this->write($line);

        self::assertSame($files, $this->filesByLocale());
        self::assertSame($onDisk, $this->storedFilesOnDisk(), 'no source fetched again');
    }

    #[Test]
    public function aLanguageWhoseSourceLeavesTheLineTakesTheRootFileBack(): void
    {
        $this->write([['source' => 'alpha.png', 'i18n' => [$this->otherLocale => ['source' => 'bravo.png']]]]);
        $replaced = $this->filesByLocale()[$this->otherLocale];

        $this->write([['source' => 'alpha.png']]);

        self::assertCount(1, array_unique($this->filesByLocale()));
        self::assertFileDoesNotExist($this->uploadDir().'/'.$replaced, 'removed once the line is committed');
    }

    #[Test]
    public function aChangedSourceReplacesTheFileOfItsLanguageOnly(): void
    {
        $this->write([['source' => 'alpha.png', 'i18n' => [$this->otherLocale => ['source' => 'bravo.png']]]]);
        $before = $this->filesByLocale();

        $this->write([['source' => 'alpha.png', 'i18n' => [$this->otherLocale => ['source' => 'charlie.png']]]]);
        $after = $this->filesByLocale();

        self::assertStringStartsWith('charlie-', $after[$this->otherLocale]);
        self::assertSame(array_diff_key($before, [$this->otherLocale => true]), array_diff_key($after, [$this->otherLocale => true]));
        self::assertFileDoesNotExist($this->uploadDir().'/'.$before[$this->otherLocale]);
    }

    #[Test]
    public function aLanguageLeftWithoutSourceLosesItsImportedFile(): void
    {
        $defaultLocale = Lang::getDefaultLanguage()->getLocale();

        $this->write([['i18n' => [$defaultLocale => ['source' => 'alpha.png'], $this->otherLocale => ['source' => 'bravo.png']]]]);
        $dropped = $this->filesByLocale()[$this->otherLocale];

        $this->write([['i18n' => [$defaultLocale => ['source' => 'alpha.png']]]]);

        self::assertSame([$defaultLocale], array_keys(array_filter($this->filesByLocale())), 'the core shows the file of the default language there');
        self::assertFileDoesNotExist($this->uploadDir().'/'.$dropped);
    }

    #[Test]
    public function anImageLeavingTheLineTakesEveryFileOfItsLanguages(): void
    {
        $this->write([['source' => 'alpha.png', 'i18n' => [$this->otherLocale => ['source' => 'bravo.png']]]]);
        $removed = $this->storedFilesOnDisk();

        $this->write([['source' => 'delta.png']]);

        foreach ($removed as $file) {
            self::assertFileDoesNotExist($this->uploadDir().'/'.$file);
        }

        self::assertCount(1, $this->storedFilesOnDisk());
    }

    /** @param list<array<string, mixed>> $images */
    private function write(array $images): void
    {
        $container = static::getContainer();
        $parameters = new ImportParameters(fallbackCategoryId: $this->categoryId, imagesDirectory: $this->directoryName);
        $line = $container->get(ProductLineFactory::class)->create(
            ['ref' => self::REF, 'title' => 'Image locale file', 'price' => 10, 'images' => $images],
            Lang::getDefaultLanguage()->getLocale(),
            $parameters,
        );

        $report = new LineReport();
        $container->get(ProductWriter::class)->write($line, $parameters, $report);
        self::assertSame([], $report->warnings());
    }

    /** @return array<string, ?string> locale => own file of the only image of the product */
    private function filesByLocale(): array
    {
        ProductImageTableMap::clearInstancePool();
        ProductImageI18nTableMap::clearInstancePool();

        $image = $this->image();
        $files = [];

        foreach (ProductImageI18nQuery::create()->filterById($image->getId())->orderByLocale()->find() as $translation) {
            $files[(string) $translation->getLocale()] = $translation->getFile();
        }

        // Same order as the active languages, for the comparisons.
        $rank = array_flip($this->activeLocales);
        uksort($files, static fn (string $left, string $right): int => ($rank[$left] ?? \PHP_INT_MAX) <=> ($rank[$right] ?? \PHP_INT_MAX));

        return $files;
    }

    /** @return list<string> files of the images of the product present in the upload directory */
    private function storedFilesOnDisk(): array
    {
        ProductImageTableMap::clearInstancePool();
        ProductImageI18nTableMap::clearInstancePool();
        $files = [];

        foreach (ProductImageQuery::create()->filterByProductId($this->productId())->find() as $image) {
            foreach ($image->getStoredFiles() as $file) {
                if (is_file($image->getUploadDir().'/'.$file)) {
                    $files[] = $file;
                }
            }
        }

        sort($files);

        return $files;
    }

    private function image(): ProductImage
    {
        $images = ProductImageQuery::create()->filterByProductId($this->productId())->find();
        self::assertCount(1, $images);

        return $images->getFirst();
    }

    private function uploadDir(): string
    {
        return (new ProductImage())->getUploadDir();
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
            foreach ($image->getStoredFiles() as $file) {
                @unlink($image->getUploadDir().'/'.$file);
            }
        }

        JsonlImportMediaQuery::create()->filterByProductId($product->getId())->delete();
        $product->delete();
    }
}
