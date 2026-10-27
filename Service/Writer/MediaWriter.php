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
use JsonLinesImporter\Service\ShopContext;
use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Model\Product;
use Thelia\Model\ProductDocument;
use Thelia\Model\ProductDocumentQuery;
use Thelia\Model\ProductImage;
use Thelia\Model\ProductImageI18nQuery;
use Thelia\Model\ProductImageQuery;

/**
 * Images and documents of a product, matched with the existing files by their source.
 *
 * Files are fetched before the transaction of the line (prepare), then attached inside it (write).
 * A known source is not fetched again: only its texts, visibility and position are updated. A file the
 * module imported is removed when it leaves the line; a file added by hand is never touched.
 *
 * The file of an image is translated (product_image_i18n.file): each language gets the file of its source,
 * the root source for every active language, the source of an i18n block for its own. An image is matched
 * by its identity source (MediaLine::$identity); every source it uses is remembered with the file it gave.
 */
final readonly class MediaWriter
{
    public function __construct(
        private MediaFetcher $fetcher,
        private MediaProvenance $provenance,
        private ShopContext $shopContext,
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
                $object = null === $productId ? null : $this->existingObject($type, $productId, $media->identity);
                $sources = $this->sourcesOf($type, $media);

                if (null === $object && [] === $sources) {
                    $report->warn(\sprintf('Media ignored: %s has no file in the selected languages', $media->identity));

                    continue;
                }

                foreach ($sources as $source) {
                    $hash = $type->value.':'.MediaProvenance::hash($source);

                    if (isset($fetched[$hash]) || $this->isKnown($type, $object, $source)) {
                        continue;
                    }

                    try {
                        $fetched[$hash] = $this->fetcher->fetch($source, $kind, $directory);
                    } catch (ImportException $exception) {
                        $report->warn('Media ignored: '.$exception->getMessage());
                    }
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
    public function write(Product $product, ProductLine $line, ImportParameters $parameters, array $fetched, FileOperations $files, ConnectionInterface $con): array
    {
        $keys = $this->sync($product, $line->images, MediaType::PRODUCT_IMAGE, $parameters, $fetched, $files, $con);
        $this->sync($product, $line->documents, MediaType::PRODUCT_DOCUMENT, $parameters, $fetched, $files, $con);

        return $keys;
    }

    /**
     * @param array<string, FetchedFile> $fetched
     *
     * @return array<string, int>
     */
    private function sync(Product $product, Field $field, MediaType $type, ImportParameters $parameters, array $fetched, FileOperations $files, ConnectionInterface $con): array
    {
        if (!$field->present) {
            return [];
        }

        $productId = (int) $product->getId();
        $keptIds = [];
        $keys = [];

        /** @var list<MediaLine> $medias */
        $medias = $field->value ?? [];

        foreach ($medias as $media) {
            $object = $this->existingObject($type, $productId, $media->identity);

            if (null !== $object) {
                $keptIds[(int) $object->getId()] = true;
            }
        }

        // Removed first: the core closes the gap a media leaves, which would shift the positions written below.
        $this->removeMediaNotIn($type, $productId, $keptIds, $files, $con);

        foreach ($medias as $index => $media) {
            $object = $this->existingObject($type, $productId, $media->identity);
            $isNew = null === $object;

            if ($isNew && !$this->hasFetchedFile($type, $media, $fetched)) {
                continue;
            }

            $object ??= $this->createObject($type, $product, $con);

            if ($object instanceof ProductImage) {
                $this->attachFiles($object, $media, $parameters, $fetched, $files, $con);
            } elseif ($isNew) {
                $this->attachDocumentFile($object, $media, $fetched, $files);
            }

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

        return $keys;
    }

    /** @param array<int, true> $keptIds */
    private function removeMediaNotIn(MediaType $type, int $productId, array $keptIds, FileOperations $files, ConnectionInterface $con): void
    {
        $removedIds = [];

        foreach ($this->provenance->findAll($type, $productId) as $record) {
            $objectId = (int) $record->getObjectId();

            if (isset($keptIds[$objectId])) {
                continue;
            }

            if (!isset($removedIds[$objectId])) {
                $removedIds[$objectId] = true;
                $object = $this->findObject($type, $objectId);

                if (null !== $object && (int) $object->getProductId() === $productId) {
                    $storedFiles = $object instanceof ProductImage ? $object->getStoredFiles() : [$object->getFile()];

                    foreach ($storedFiles as $storedFile) {
                        $files->deleteAfterCommit($object->getUploadDir().\DIRECTORY_SEPARATOR.$storedFile, $object instanceof ProductImage);
                    }

                    $object->delete($con);
                }
            }

            $record->delete($con);
        }
    }

    /**
     * The row of a new media, saved to get the id its file names carry. The file of an image lives on its
     * translations (attachFiles()); a document needs one on its row from the start.
     */
    private function createObject(MediaType $type, Product $product, ConnectionInterface $con): ProductImage|ProductDocument
    {
        $object = MediaType::PRODUCT_IMAGE === $type
            ? new ProductImage()
            : (new ProductDocument())->setFile('importing');

        $object
            ->setProductId((int) $product->getId())
            ->setVisible(1)
            ->save($con);

        return $object;
    }

    /** @param array<string, FetchedFile> $fetched */
    private function attachDocumentFile(ProductDocument $document, MediaLine $media, array $fetched, FileOperations $files): void
    {
        $source = (string) $media->source;
        $file = $fetched[MediaType::PRODUCT_DOCUMENT->value.':'.MediaProvenance::hash($source)];
        $fileName = self::fileName($file, (int) $document->getId());

        $files->place($file->temporaryPath, $document->getUploadDir().\DIRECTORY_SEPARATOR.$fileName);
        $document->setFile($fileName);

        $this->provenance->record(MediaType::PRODUCT_DOCUMENT, (int) $document->getProductId(), $source, (int) $document->getId(), $fileName);
    }

    /**
     * Gives each language of the image the file of its source, a source shared by several languages
     * being stored once. A language of the selection whose imported file no longer has a source is
     * emptied: the core shows the file of the default language there. A file no language stores any
     * more is removed once the line is committed.
     *
     * @param array<string, FetchedFile> $fetched
     */
    private function attachFiles(ProductImage $image, MediaLine $media, ImportParameters $parameters, array $fetched, FileOperations $files, ConnectionInterface $con): void
    {
        $type = MediaType::PRODUCT_IMAGE;
        $productId = (int) $image->getProductId();
        $imageId = (int) $image->getId();
        $before = $image->getStoredFiles();

        /** @var array<string, \JsonLinesImporter\Model\JsonlImportMedia> $records source hash => provenance of this image */
        $records = [];

        foreach ($this->provenance->findAll($type, $productId) as $record) {
            if ((int) $record->getObjectId() === $imageId) {
                $records[$record->getSourceHash()] = $record;
            }
        }

        /** @var array<string, string> $fileOfSource source hash => file name */
        $fileOfSource = [];
        $wanted = $media->filesByLocale($this->shopContext->activeLocales());

        foreach ($wanted as $locale => $source) {
            $hash = MediaProvenance::hash($source);

            if (!isset($fileOfSource[$hash])) {
                $known = isset($records[$hash]) ? $records[$hash]->getFile() : null;
                $file = $fetched[$type->value.':'.$hash] ?? null;

                if (null !== $file) {
                    $fileOfSource[$hash] = $this->placeImageFile($file, $image, $locale, $fileOfSource, $files);
                } elseif (null !== $known && '' !== $known) {
                    $fileOfSource[$hash] = $known;
                } else {
                    // Fetch failed, already reported: the language keeps the file it has.
                    continue;
                }

                $this->provenance->record($type, $productId, $source, $imageId, $fileOfSource[$hash]);
            }

            $image->setLocale($locale);

            if ($image->getOwnFile() !== $fileOfSource[$hash]) {
                $image->setFile($fileOfSource[$hash]);
            }
        }

        $importedFiles = array_filter(array_map(static fn ($record): ?string => $record->getFile(), $records));

        foreach (ProductImageI18nQuery::create()->filterById($imageId)->find() as $translation) {
            $locale = (string) $translation->getLocale();

            if (isset($wanted[$locale]) || !$parameters->isLocaleSelected($locale)) {
                continue;
            }

            $image->setLocale($locale);

            if (\in_array($image->getOwnFile(), $importedFiles, true)) {
                $image->setFile(null);
            }
        }

        $after = $image->getStoredFiles();

        foreach (array_diff($before, $after) as $removedFile) {
            $files->deleteAfterCommit($image->getUploadDir().\DIRECTORY_SEPARATOR.$removedFile, true);
        }

        $identityHash = MediaProvenance::hash($media->identity);

        foreach ($records as $hash => $record) {
            if ($hash !== $identityHash && !isset($fileOfSource[$hash]) && !\in_array($record->getFile(), $after, true)) {
                $record->delete($con);
            }
        }

        // The identity matches the image at the next import, even when its language is not selected.
        if (!isset($records[$identityHash]) && !isset($fileOfSource[$identityHash])) {
            $this->provenance->record($type, $productId, $media->identity, $imageId);
        }
    }

    /**
     * Same naming as Thelia's FileManager (sanitized original name, then the id of the row), with the
     * extension of the detected type. A name already stored, or given to another source of the image,
     * takes the language then a counter, as FileManager::freeLocalizedFileName() does: a file is never
     * written over, a rollback could not bring it back.
     *
     * @param array<string, string> $taken source hash => file name given in this line
     */
    private function placeImageFile(FetchedFile $file, ProductImage $image, string $locale, array $taken, FileOperations $files): string
    {
        $directory = $image->getUploadDir();
        $isFree = static fn (string $name): bool => !\in_array($name, $taken, true) && !file_exists($directory.\DIRECTORY_SEPARATOR.$name);
        $baseName = $file->baseName().'-'.(int) $image->getId();
        $suffix = '.'.$file->extension();
        $fileName = $baseName.$suffix;

        if (!$isFree($fileName)) {
            $baseName .= '-'.strtolower((string) preg_replace('/[^a-zA-Z0-9_]/', '', $locale));
            $fileName = $baseName.$suffix;

            for ($counter = 1; !$isFree($fileName); ++$counter) {
                $fileName = $baseName.'-'.$counter.$suffix;
            }
        }

        $files->place($file->temporaryPath, $directory.\DIRECTORY_SEPARATOR.$fileName);

        return $fileName;
    }

    /**
     * Same naming as Thelia's FileManager (sanitized original name, then the id of the row), with the
     * extension of the detected type.
     */
    private static function fileName(FetchedFile $file, int $objectId): string
    {
        return $file->baseName().'-'.$objectId.'.'.$file->extension();
    }

    /**
     * The sources whose file the line asks for: the file of every language of an image, the one of a document.
     *
     * @return list<string>
     */
    private function sourcesOf(MediaType $type, MediaLine $media): array
    {
        if (MediaType::PRODUCT_IMAGE !== $type) {
            return [(string) $media->source];
        }

        return array_values(array_unique($media->filesByLocale($this->shopContext->activeLocales())));
    }

    /**
     * Whether the file of a source is already stored by the media: a document is matched by its only
     * source, an image remembers the file each of its sources gave.
     */
    private function isKnown(MediaType $type, ProductImage|ProductDocument|null $object, string $source): bool
    {
        if (null === $object) {
            return false;
        }

        if (!$object instanceof ProductImage) {
            return true;
        }

        $record = $this->provenance->find($type, (int) $object->getProductId(), $source);

        return null !== $record
            && (int) $record->getObjectId() === (int) $object->getId()
            && null !== $record->getFile() && '' !== $record->getFile();
    }

    /** @param array<string, FetchedFile> $fetched */
    private function hasFetchedFile(MediaType $type, MediaLine $media, array $fetched): bool
    {
        foreach ($this->sourcesOf($type, $media) as $source) {
            if (isset($fetched[$type->value.':'.MediaProvenance::hash($source)])) {
                return true;
            }
        }

        return false;
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
