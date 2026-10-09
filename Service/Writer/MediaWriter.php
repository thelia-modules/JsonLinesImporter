<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service\Writer;

use JsonLinesImporter\Dto\FetchedFile;
use JsonLinesImporter\Dto\Field;
use JsonLinesImporter\Dto\ImportParameters;
use JsonLinesImporter\Dto\MediaLine;
use JsonLinesImporter\Dto\ProductLine;
use JsonLinesImporter\Enum\MediaKind;
use JsonLinesImporter\Enum\MediaType;
use JsonLinesImporter\Exception\ImportException;
use JsonLinesImporter\Service\LineReport;
use JsonLinesImporter\Service\MediaFetcher;
use JsonLinesImporter\Service\MediaProvenance;
use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Model\Product;
use Thelia\Model\ProductDocument;
use Thelia\Model\ProductDocumentQuery;
use Thelia\Model\ProductImage;
use Thelia\Model\ProductImageQuery;

/**
 * Images and documents of a product, matched with the existing files by their source.
 *
 * Files are fetched before the transaction of the line (prepare), then attached inside it (write).
 * A known source is not fetched again: only its texts, visibility and position are updated. A file the
 * module imported is removed when it leaves the line; a file added by hand is never touched.
 */
final readonly class MediaWriter
{
    public function __construct(
        private MediaFetcher $fetcher,
        private MediaProvenance $provenance,
    ) {
    }

    /**
     * @return array<string, FetchedFile> source hash => file, for the sources not known yet
     */
    public function prepare(ProductLine $line, ?int $productId, ImportParameters $parameters, LineReport $report): array
    {
        $fetched = [];

        foreach ([[$line->images, MediaType::PRODUCT_IMAGE, MediaKind::IMAGE, $parameters->imagesDirectory], [$line->documents, MediaType::PRODUCT_DOCUMENT, MediaKind::DOCUMENT, $parameters->documentsDirectory]] as [$field, $type, $kind, $directory]) {
            if (!$field->hasValue()) {
                continue;
            }

            /** @var MediaLine $media */
            foreach ($field->value as $media) {
                $hash = $type->value.':'.MediaProvenance::hash($media->source);

                if (isset($fetched[$hash])) {
                    continue;
                }

                if (null !== $productId && null !== $this->existingObject($type, $productId, $media->source)) {
                    continue;
                }

                try {
                    $fetched[$hash] = $this->fetcher->fetch($media->source, $kind, $directory);
                } catch (ImportException $exception) {
                    $report->warn('Media ignored: '.$exception->getMessage());
                }
            }
        }

        return $fetched;
    }

    /**
     * @param array<string, FetchedFile> $fetched
     *
     * @return array<string, int> image `_key` => product image id, for the variants
     */
    public function write(Product $product, ProductLine $line, array $fetched, FileOperations $files, ConnectionInterface $con): array
    {
        $keys = $this->sync($product, $line->images, MediaType::PRODUCT_IMAGE, $fetched, $files, $con);
        $this->sync($product, $line->documents, MediaType::PRODUCT_DOCUMENT, $fetched, $files, $con);

        return $keys;
    }

    /**
     * @param array<string, FetchedFile> $fetched
     *
     * @return array<string, int>
     */
    private function sync(Product $product, Field $field, MediaType $type, array $fetched, FileOperations $files, ConnectionInterface $con): array
    {
        if (!$field->present) {
            return [];
        }

        $productId = (int) $product->getId();
        $keptHashes = [];
        $keys = [];

        /** @var list<MediaLine> $medias */
        $medias = $field->value ?? [];

        foreach ($medias as $index => $media) {
            $hash = MediaProvenance::hash($media->source);
            $object = $this->existingObject($type, $productId, $media->source);
            $isNew = null === $object;

            if ($isNew) {
                $file = $fetched[$type->value.':'.$hash] ?? null;

                if (null === $file) {
                    continue;
                }

                $object = $this->createObject($type, $product, $file, $files, $con);
                $this->provenance->record($type, $productId, $media->source, (int) $object->getId());
            }

            $keptHashes[$hash] = true;

            if ($media->visible->present || $isNew) {
                $object->setVisible((int) $media->visible->or(true));
            }

            // Thelia overwrites the position on insert: it is applied once the row exists.
            $object->setPosition($media->position ?? $index + 1);

            foreach ($media->i18n->values as $locale => $fields) {
                $object->setLocale($locale);

                foreach ($fields as $name => $value) {
                    match ($name) {
                        'title' => $object->setTitle($value),
                        'chapo' => $object->setChapo($value),
                        'description' => $object->setDescription($value),
                        'postscriptum' => $object->setPostscriptum($value),
                        default => null,
                    };
                }
            }

            $object->save($con);

            if (null !== $media->key && $object instanceof ProductImage) {
                $keys[$media->key] = (int) $object->getId();
            }
        }

        foreach ($this->provenance->findAll($type, $productId) as $record) {
            if (isset($keptHashes[$record->getSourceHash()])) {
                continue;
            }

            $object = $this->findObject($type, (int) $record->getObjectId());

            if (null !== $object && (int) $object->getProductId() === $productId) {
                $files->deleteAfterCommit($object->getUploadDir().\DIRECTORY_SEPARATOR.$object->getFile(), $object instanceof ProductImage);
                $object->delete($con);
            }

            $record->delete($con);
        }

        return $keys;
    }

    private function createObject(MediaType $type, Product $product, FetchedFile $file, FileOperations $files, ConnectionInterface $con): ProductImage|ProductDocument
    {
        $object = MediaType::PRODUCT_IMAGE === $type ? new ProductImage() : new ProductDocument();

        $object
            ->setProductId((int) $product->getId())
            ->setVisible(1)
            ->setFile('importing')
            ->save($con);

        $fileName = self::fileName($file, (int) $object->getId());
        $files->place($file->temporaryPath, $object->getUploadDir().\DIRECTORY_SEPARATOR.$fileName);

        $object->setFile($fileName);

        return $object;
    }

    /**
     * Same naming as Thelia's FileManager (sanitized original name, then the id of the row), with the
     * extension of the detected type.
     */
    private static function fileName(FetchedFile $file, int $objectId): string
    {
        return $file->baseName().'-'.$objectId.'.'.$file->extension();
    }

    private function existingObject(MediaType $type, int $productId, string $source): ProductImage|ProductDocument|null
    {
        $record = $this->provenance->find($type, $productId, $source);

        if (null === $record) {
            return null;
        }

        $object = $this->findObject($type, (int) $record->getObjectId());

        return null !== $object && (int) $object->getProductId() === $productId ? $object : null;
    }

    private function findObject(MediaType $type, int $objectId): ProductImage|ProductDocument|null
    {
        return MediaType::PRODUCT_IMAGE === $type
            ? ProductImageQuery::create()->findPk($objectId)
            : ProductDocumentQuery::create()->findPk($objectId);
    }
}
