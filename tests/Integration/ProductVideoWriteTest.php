<?php

declare(strict_types=1);

namespace JsonLinesImporter\Tests\Integration;

use JsonLinesImporter\Dto\ImportParameters;
use JsonLinesImporter\Enum\MediaType;
use JsonLinesImporter\Model\JsonlImportMediaQuery;
use JsonLinesImporter\Service\LineReport;
use JsonLinesImporter\Service\ProductLineFactory;
use JsonLinesImporter\Service\Writer\ProductWriter;
use PHPUnit\Framework\Attributes\Test;
use Thelia\Model\Category;
use Thelia\Model\CategoryQuery;
use Thelia\Model\Lang;
use Thelia\Model\Map\ProductVideoI18nTableMap;
use Thelia\Model\Map\ProductVideoTableMap;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductVideo;
use Thelia\Model\ProductVideoQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The videos of a line become ProductVideo rows after the images of the product: platform videos and
 * hosted files, matched again on a replay, removed when they leave the line, never touching a video
 * added by hand.
 *
 * Prerequisites: a shop test database (core 3.2 or later) where the module is activated. No network:
 * the files are read from a local directory.
 */
final class ProductVideoWriteTest extends IntegrationTestCase
{
    private const REF = 'JLI-VIDEO';

    private const YOUTUBE_ID = 'aBcDeFgHi01';

    // Each line is written in its own transaction: a surrounding one would swallow its rollbacks.
    protected bool $useTransaction = false;

    private ?int $categoryId = null;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        if (null === ModuleQuery::create()->filterByCode('JsonLinesImporter')->filterByActivate(1)->findOne()) {
            self::markTestSkipped('The JsonLinesImporter module is not active in the test database.');
        }

        $this->cleanUp();

        $category = (new Category())->setParent(0)->setVisible(0);
        $category->setLocale(Lang::getDefaultLanguage()->getLocale())->setTitle('JLI video');
        $category->save();
        $this->categoryId = (int) $category->getId();

        $this->directory = sys_get_temp_dir().'/jli-video-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        // A real PNG (1x1), and the file type box an MP4 starts with.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        file_put_contents($this->directory.'/front.png', $png);
        file_put_contents($this->directory.'/back.png', $png);
        file_put_contents($this->directory.'/clip.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64));
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
    public function theVideosAreWrittenAfterTheImagesThenMatchedAgainOnAReplay(): void
    {
        $report = $this->write([
            ['source' => 'https://www.youtube.com/watch?v='.self::YOUTUBE_ID, 'position' => 2, 'title' => 'Demo', 'alt' => 'Demo of the lamp'],
            ['source' => 'https://vimeo.com/123456789', 'position' => 1, 'visible' => false],
            ['source' => 'clip.mp4', 'position' => 3, 'i18n' => ['en_US' => ['title' => 'Clip']]],
        ]);

        self::assertSame([], $report->warnings());
        self::assertSame([1, 2], $this->imagePositions());

        $videos = $this->videos();
        self::assertSame(['vimeo', 'youtube', 'file'], array_column($videos, 'provider'), 'the videos follow their own positions');
        self::assertSame([3, 4, 5], array_column($videos, 'position'), 'the videos come after the images');
        self::assertSame([0, 1, 1], array_column($videos, 'visible'));
        self::assertSame('123456789', $videos[0]['external_id']);
        self::assertSame(self::YOUTUBE_ID, $videos[1]['external_id']);
        self::assertSame('Demo of the lamp', $videos[1]['object']->setLocale(Lang::getDefaultLanguage()->getLocale())->getAlt());
        self::assertSame('Clip', $videos[2]['object']->setLocale('en_US')->getTitle());

        $hosted = $videos[2]['object'];
        self::assertTrue($hosted->isHostedFile());
        self::assertStringEndsWith('.mp4', $hosted->getFile());
        self::assertFileExists($hosted->getUploadDir().'/'.$hosted->getFile());

        $ids = array_column($videos, 'id');

        // The source file is gone: a replay that fetched it again would report it.
        unlink($this->directory.'/clip.mp4');

        $report = $this->write([
            ['source' => 'https://youtu.be/'.self::YOUTUBE_ID, 'position' => 2],
            ['source' => 'https://player.vimeo.com/video/123456789', 'position' => 1],
            ['source' => 'clip.mp4', 'position' => 3],
        ]);

        self::assertSame([], $report->warnings());
        self::assertSame($ids, array_column($this->videos(), 'id'), 'another address of the same video is the same video');
        self::assertSame([0, 1, 1], array_column($this->videos(), 'visible'), 'a visibility absent from the line is kept');
    }

    #[Test]
    public function aVideoLeavingTheLineIsRemovedButAVideoAddedByHandIsKept(): void
    {
        $this->write([
            ['source' => 'https://www.youtube.com/watch?v='.self::YOUTUBE_ID],
            ['source' => 'clip.mp4'],
        ]);

        $hosted = $this->videos()[1]['object'];
        $hostedPath = $hosted->getUploadDir().'/'.$hosted->getFile();
        self::assertFileExists($hostedPath);

        $byHand = (new ProductVideo())
            ->setProductId($this->productId())
            ->setProvider('dailymotion')
            ->setExternalId('x8abc12');
        $byHand->save();

        $report = $this->write([['source' => 'https://www.youtube.com/watch?v='.self::YOUTUBE_ID]]);

        self::assertSame([], $report->warnings());
        self::assertSame(['dailymotion', 'youtube'], array_column($this->videos(), 'provider'));
        self::assertFileDoesNotExist($hostedPath, 'the file of a removed video is deleted');
        self::assertSame(1, JsonlImportMediaQuery::create()->filterByMediaType(MediaType::PRODUCT_VIDEO->value)->filterByProductId($this->productId())->count());

        $this->write(null);

        self::assertSame(['dailymotion'], array_column($this->videos(), 'provider'), 'null removes the videos imported by the module only');
        self::assertSame(0, JsonlImportMediaQuery::create()->filterByMediaType(MediaType::PRODUCT_VIDEO->value)->filterByProductId($this->productId())->count());
    }

    #[Test]
    public function aLineWhoseVideosAreAllInvalidLeavesTheVideosInPlace(): void
    {
        $this->write([['source' => 'https://www.youtube.com/watch?v='.self::YOUTUBE_ID]]);

        $report = $this->write([
            ['source' => 'https://example.com/a-page-about-the-lamp'],
            ['source' => 'missing.mp4'],
        ]);

        self::assertSame(['youtube'], array_column($this->videos(), 'provider'));
        self::assertContains('No valid video in the line: the videos of the product are left unchanged', $report->warnings());
        self::assertCount(3, $report->warnings(), 'each invalid video is reported');
    }

    /** @param list<array<string, mixed>>|null $videos */
    private function write(?array $videos): LineReport
    {
        $container = static::getContainer();
        $parameters = new ImportParameters(
            fallbackCategoryId: $this->categoryId,
            imagesDirectory: $this->directory,
            videosDirectory: $this->directory,
        );
        $line = $container->get(ProductLineFactory::class)->create(
            [
                'ref' => self::REF,
                'title' => 'Video',
                'price' => 10,
                'images' => [['source' => 'front.png'], ['source' => 'back.png']],
                'videos' => $videos,
            ],
            Lang::getDefaultLanguage()->getLocale(),
            $parameters,
        );

        $report = new LineReport();
        $container->get(ProductWriter::class)->write($line, $parameters, $report);

        return $report;
    }

    private function productId(): int
    {
        return (int) ProductQuery::create()->findOneByRef(self::REF)?->getId();
    }

    /** @return list<int> */
    private function imagePositions(): array
    {
        return array_map(
            static fn ($image): int => (int) $image->getPosition(),
            ProductImageQuery::create()->filterByProductId($this->productId())->orderByPosition()->find()->getData(),
        );
    }

    /** @return list<array{id: int, provider: string, external_id: ?string, position: int, visible: int, object: ProductVideo}> */
    private function videos(): array
    {
        ProductVideoTableMap::clearInstancePool();
        ProductVideoI18nTableMap::clearInstancePool();

        return array_map(
            static fn (ProductVideo $video): array => [
                'id' => (int) $video->getId(),
                'provider' => (string) $video->getProvider(),
                'external_id' => $video->getExternalId(),
                'position' => (int) $video->getPosition(),
                'visible' => (int) $video->getVisible(),
                'object' => $video,
            ],
            ProductVideoQuery::create()->filterByProductId($this->productId())->orderByPosition()->find()->getData(),
        );
    }

    private function cleanUp(): void
    {
        $product = ProductQuery::create()->findOneByRef(self::REF);

        if (null === $product) {
            return;
        }

        foreach (ProductVideoQuery::create()->filterByProductId($product->getId())->find() as $video) {
            if ($video->isHostedFile()) {
                @unlink($video->getUploadDir().'/'.$video->getFile());
            }
        }

        foreach (ProductImageQuery::create()->filterByProductId($product->getId())->find() as $image) {
            @unlink($image->getUploadDir().'/'.$image->getFile());
        }

        JsonlImportMediaQuery::create()->filterByProductId($product->getId())->delete();
        $product->delete();
    }
}
