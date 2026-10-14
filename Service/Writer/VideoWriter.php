<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service\Writer;

use JsonLinesImporter\Dto\FetchedFile;
use JsonLinesImporter\Dto\ImportParameters;
use JsonLinesImporter\Dto\ProductLine;
use JsonLinesImporter\Dto\VideoLine;
use JsonLinesImporter\Dto\VideoSource;
use JsonLinesImporter\Enum\MediaKind;
use JsonLinesImporter\Enum\MediaType;
use JsonLinesImporter\Exception\ImportException;
use JsonLinesImporter\Service\LineReport;
use JsonLinesImporter\Service\MediaFetcher;
use JsonLinesImporter\Service\MediaProvenance;
use JsonLinesImporter\Service\VideoSourceClassifier;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Action\ProductVideo as ProductVideoAction;
use Thelia\Core\File\FileConfiguration;
use Thelia\Domain\Media\Video\VideoProvider;
use Thelia\Model\Product;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductVideo;
use Thelia\Model\ProductVideoQuery;

/**
 * Videos of a product (ProductVideo of the core), matched with the existing videos by their identity:
 * the platform and the identifier of a platform video, the source of a file.
 *
 * Files are fetched before the transaction of the line (prepare), then attached inside it (write). A
 * known video is not fetched again: only its texts, visibility and position are updated. A video the
 * module imported is removed when it leaves the line; a video added by hand is never touched.
 *
 * Images and videos share one sequence of positions: the videos of the line come after the images and
 * the videos added by hand, in the order of their `position`.
 */
final readonly class VideoWriter
{
    public function __construct(
        private VideoSourceClassifier $classifier,
        private MediaFetcher $fetcher,
        private MediaProvenance $provenance,
        private ProductVideoAction $videoAction,
    ) {
    }

    /**
     * @return array<string, FetchedFile> fetch key => file, for the files not known yet
     */
    public function prepare(ProductLine $line, ?int $productId, ImportParameters $parameters, LineReport $report): array
    {
        if (!$line->videos->hasValue()) {
            return [];
        }

        $fetched = [];

        /** @var VideoLine $video */
        foreach ($line->videos->value as $video) {
            try {
                $source = $this->classifier->classify($video->source);
            } catch (ImportException) {
                // Reported when the line is written.
                continue;
            }

            $key = self::fetchKey($source);

            if (!$source->isFile() || isset($fetched[$key]) || (null !== $productId && null !== $this->existingVideo($productId, $source))) {
                continue;
            }

            try {
                $file = $this->fetcher->fetch($source->source, MediaKind::VIDEO, $parameters->videosDirectory);
            } catch (ImportException $exception) {
                $report->warn('Video ignored: '.$exception->getMessage());

                continue;
            }

            if (!\array_key_exists($file->mimeType, FileConfiguration::getVideoConfig()['validMimeTypes'])) {
                $file->discard();
                $report->warn(\sprintf('Video ignored: %s: the shop does not accept %s videos', $source->source, $file->mimeType));

                continue;
            }

            $fetched[$key] = $file;
        }

        return $fetched;
    }

    /**
     * @param array<string, FetchedFile> $fetched
     */
    public function write(Product $product, ProductLine $line, array $fetched, FileOperations $files, LineReport $report, ConnectionInterface $con): void
    {
        if (!$line->videos->present) {
            return;
        }

        $productId = (int) $product->getId();

        /** @var list<VideoLine> $videos */
        $videos = $line->videos->value ?? [];
        usort($videos, static fn (VideoLine $left, VideoLine $right): int => ($left->position ?? \PHP_INT_MAX) <=> ($right->position ?? \PHP_INT_MAX));

        /** @var array<string, array{VideoLine, VideoSource, ?ProductVideo}> $valid identity hash => video */
        $valid = [];

        foreach ($videos as $video) {
            try {
                $source = $this->classifier->classify($video->source);
            } catch (ImportException $exception) {
                $report->warn('Video ignored: '.$exception->getMessage());

                continue;
            }

            $hash = MediaProvenance::hash($source->identity());

            if (isset($valid[$hash])) {
                continue;
            }

            $object = $this->existingVideo($productId, $source);

            // A file that could not be fetched was reported by prepare().
            if (null === $object && $source->isFile() && !isset($fetched[self::fetchKey($source)])) {
                continue;
            }

            $valid[$hash] = [$video, $source, $object];
        }

        // A list whose videos are all invalid leaves the current videos in place: a mistyped address
        // must not erase valid videos.
        if ([] !== $videos && [] === $valid) {
            $report->warn('No valid video in the line: the videos of the product are left unchanged');

            return;
        }

        // Removed first: the core closes the gap a video leaves, which would shift the positions written below.
        $this->removeVideosNotIn($productId, $valid, $files, $con);

        $position = $this->lastPositionBeforeImportedVideos($productId, $con);

        foreach ($valid as [$video, $source, $object]) {
            $isNew = null === $object;

            if ($isNew) {
                $object = $this->createVideo($productId, $source, $fetched[self::fetchKey($source)] ?? null, $files, $con);
                $this->provenance->record(MediaType::PRODUCT_VIDEO, $productId, $source->identity(), (int) $object->getId());
            }

            if ($video->visible->present || $isNew) {
                $object->setVisible((int) $video->visible->or(true));
            }

            // Thelia overwrites the position on insert: it is applied once the row exists.
            $object->setPosition(++$position);

            foreach ($video->i18n->values as $locale => $fields) {
                $object->setLocale($locale);

                foreach ($fields as $name => $value) {
                    match ($name) {
                        'title' => $object->setTitle($value),
                        'alt' => $object->setAlt($value),
                        'chapo' => $object->setChapo($value),
                        'description' => $object->setDescription($value),
                        'postscriptum' => $object->setPostscriptum($value),
                        default => null,
                    };
                }
            }

            $object->save($con);
        }
    }

    private function createVideo(int $productId, VideoSource $source, ?FetchedFile $file, FileOperations $files, ConnectionInterface $con): ProductVideo
    {
        $object = (new ProductVideo())
            ->setProductId($productId)
            ->setVisible(1);

        if (!$source->isFile()) {
            $object
                ->setProvider($source->provider->value)
                ->setExternalId($source->externalId)
                ->save($con);

            return $object;
        }

        if (null === $file) {
            throw new \LogicException(\sprintf('The video %s was not fetched.', $source->source));
        }

        $object
            ->setProvider(VideoProvider::File->value)
            ->setFile('importing')
            ->save($con);

        // Same naming as Thelia's FileManager (sanitized original name, then the id of the row), with
        // the extension of the detected type.
        $fileName = $file->baseName().'-'.$object->getId().'.'.$file->extension();
        $files->place($file->temporaryPath, $object->getUploadDir().\DIRECTORY_SEPARATOR.$fileName);

        $object->setFile($fileName);

        return $object;
    }

    /**
     * @param array<string, array{VideoLine, VideoSource, ?ProductVideo}> $valid identity hash => video
     */
    private function removeVideosNotIn(int $productId, array $valid, FileOperations $files, ConnectionInterface $con): void
    {
        foreach ($this->provenance->findAll(MediaType::PRODUCT_VIDEO, $productId) as $record) {
            if (isset($valid[$record->getSourceHash()])) {
                continue;
            }

            $object = ProductVideoQuery::create()->findPk((int) $record->getObjectId(), $con);

            if (null !== $object && (int) $object->getProductId() === $productId) {
                if ($object->isHostedFile()) {
                    $files->deleteAfterCommit($object->getUploadDir().\DIRECTORY_SEPARATOR.$object->getFile());
                    // The link the front office plays the file from.
                    $files->deleteAfterCommit($this->videoAction->cachedFilePath($object));
                }

                $object->delete($con);
            }

            $record->delete($con);
        }
    }

    /**
     * The last position the videos of the line come after: the images of the product and the videos
     * the module did not import.
     */
    private function lastPositionBeforeImportedVideos(int $productId, ConnectionInterface $con): int
    {
        $importedIds = array_map(
            static fn ($record): int => (int) $record->getObjectId(),
            $this->provenance->findAll(MediaType::PRODUCT_VIDEO, $productId),
        );

        $images = ProductImageQuery::create()->filterByProductId($productId)->orderByPosition(Criteria::DESC)->findOne($con);
        $videos = ProductVideoQuery::create()->filterByProductId($productId);

        if ([] !== $importedIds) {
            $videos->filterById($importedIds, Criteria::NOT_IN);
        }

        $video = $videos->orderByPosition(Criteria::DESC)->findOne($con);

        return max((int) $images?->getPosition(), (int) $video?->getPosition());
    }

    private function existingVideo(int $productId, VideoSource $source): ?ProductVideo
    {
        $record = $this->provenance->find(MediaType::PRODUCT_VIDEO, $productId, $source->identity());

        if (null === $record) {
            return null;
        }

        $object = ProductVideoQuery::create()->findPk((int) $record->getObjectId());

        return null !== $object && (int) $object->getProductId() === $productId ? $object : null;
    }

    private static function fetchKey(VideoSource $source): string
    {
        return MediaType::PRODUCT_VIDEO->value.':'.MediaProvenance::hash($source->identity());
    }
}
