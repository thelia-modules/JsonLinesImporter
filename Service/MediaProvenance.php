<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service;

use JsonLinesImporter\Enum\MediaType;
use JsonLinesImporter\Model\JsonlImportMedia;
use JsonLinesImporter\Model\JsonlImportMediaQuery;

/**
 * Remembers the source each imported file came from: a known source is never downloaded again, and only
 * the files the module imported are removed when they leave the file.
 */
final readonly class MediaProvenance
{
    public static function normalize(string $source): string
    {
        return trim($source);
    }

    public static function hash(string $source): string
    {
        return sha1(self::normalize($source));
    }

    public function find(MediaType $type, int $productId, string $source): ?JsonlImportMedia
    {
        return JsonlImportMediaQuery::create()
            ->filterByMediaType($type->value)
            ->filterByProductId($productId)
            ->filterBySourceHash(self::hash($source))
            ->findOne();
    }

    /** @return list<JsonlImportMedia> */
    public function findAll(MediaType $type, int $productId): array
    {
        return JsonlImportMediaQuery::create()
            ->filterByMediaType($type->value)
            ->filterByProductId($productId)
            ->find()
            ->getData();
    }

    public function record(MediaType $type, int $productId, string $source, int $objectId): void
    {
        $media = $this->find($type, $productId, $source) ?? (new JsonlImportMedia())
            ->setMediaType($type->value)
            ->setProductId($productId)
            ->setSource(self::normalize($source))
            ->setSourceHash(self::hash($source));

        $media->setObjectId($objectId)->save();
    }
}
