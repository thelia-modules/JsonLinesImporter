<?php

declare(strict_types=1);

namespace JsonLinesImporter\Service\Writer;

use JsonLinesImporter\Dto\FetchedFile;
use JsonLinesImporter\Dto\I18nBag;
use JsonLinesImporter\Enum\MediaKind;
use JsonLinesImporter\Enum\MediaType;
use JsonLinesImporter\Exception\ImportException;
use JsonLinesImporter\Service\LineReport;
use JsonLinesImporter\Service\MediaFetcher;
use JsonLinesImporter\Service\MediaProvenance;
use JsonLinesImporter\Service\ShopContext;
use Propel\Runtime\Propel;
use Thelia\Model\ModuleQuery;
use Thelia\Tools\URL;

/**
 * Copies the images of the HTML fields of a product into the media library (TheliaLibrary) and points
 * their `src` at it. Each source address is copied once for the whole shop, in its own transaction:
 * a line in error does not remove it, and the next lines reuse it.
 *
 * The image is created in the default locale of the shop: the library only finds the file of an image in
 * the locale of the session or the default one, and the address would be broken in any other.
 */
final readonly class HtmlImageWriter
{
    public const LIBRARY_MODULE = 'TheliaLibrary';

    public const HTML_FIELDS = ['description', 'chapo', 'postscriptum'];

    private const IMG_SRC = '~(<img\b[^>]*?\bsrc\s*=\s*)(["\'])(.*?)\2~is';

    public function __construct(
        private MediaFetcher $fetcher,
        private MediaProvenance $provenance,
        private ShopContext $shopContext,
    ) {
    }

    public static function isLibraryAvailable(): bool
    {
        return class_exists(\TheliaLibrary\Model\LibraryImage::class)
            && null !== ModuleQuery::create()->filterByCode(self::LIBRARY_MODULE)->filterByActivate(1)->findOne();
    }

    /**
     * @return bool whether a text holds an image hosted on another site
     */
    public static function hasExternalImages(string $html, ?string $shopHost): bool
    {
        if (0 === preg_match_all(self::IMG_SRC, $html, $matches)) {
            return false;
        }

        foreach ($matches[3] as $source) {
            if (self::isExternal(html_entity_decode($source), $shopHost)) {
                return true;
            }
        }

        return false;
    }

    public function rewrite(I18nBag $i18n, LineReport $report): I18nBag
    {
        if (!self::isLibraryAvailable()) {
            $report->warn('Images of the HTML fields left in place: the TheliaLibrary module is not active');

            return $i18n;
        }

        $shopHost = self::shopHost();
        $values = $i18n->values;

        foreach ($values as $locale => $fields) {
            foreach (self::HTML_FIELDS as $field) {
                $html = $fields[$field] ?? null;

                if (null === $html || '' === $html) {
                    continue;
                }

                $values[$locale][$field] = (string) preg_replace_callback(
                    self::IMG_SRC,
                    function (array $match) use ($shopHost, $report): string {
                        $source = html_entity_decode($match[3]);

                        if (!self::isExternal($source, $shopHost)) {
                            return $match[0];
                        }

                        $url = $this->libraryUrl($source, $report);

                        return null === $url ? $match[0] : $match[1].$match[2].htmlspecialchars($url, \ENT_QUOTES).$match[2];
                    },
                    $html,
                );
            }
        }

        return new I18nBag($values);
    }

    private function libraryUrl(string $source, LineReport $report): ?string
    {
        $record = $this->provenance->find(MediaType::LIBRARY_IMAGE, 0, $source);
        $image = null !== $record ? \TheliaLibrary\Model\LibraryImageQuery::create()->findPk($record->getObjectId()) : null;

        if (null === $image) {
            try {
                $file = $this->fetcher->fetch($source, MediaKind::IMAGE);
            } catch (ImportException $exception) {
                $report->warn('Image of an HTML field left in place: '.$exception->getMessage());

                return null;
            }

            try {
                $image = $this->createLibraryImage($file);
            } finally {
                $file->discard();
            }

            $this->provenance->record(MediaType::LIBRARY_IMAGE, 0, $source, (int) $image->getId());
        }

        $locale = $this->shopContext->defaultLocale();
        $fileName = (string) $image->setLocale($locale)->getFileName();
        $format = strtolower(pathinfo($fileName, \PATHINFO_EXTENSION)) ?: 'png';

        return \sprintf('/image-library/%d/full/max/0/default.%s', (int) $image->getId(), $format);
    }

    private function createLibraryImage(FetchedFile $file): \TheliaLibrary\Model\LibraryImage
    {
        $directory = \TheliaLibrary\TheliaLibrary::getImageDirectory();

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('The directory %s cannot be created.', $directory));
        }

        $baseName = strtolower((string) preg_replace('/[^a-zA-Z0-9-_\.]/', '', $file->originalName));
        $fileName = bin2hex(random_bytes(5)).'_'.('' === $baseName ? 'image.'.$file->extension() : $baseName);

        if (!copy($file->temporaryPath, $directory.$fileName)) {
            throw new \RuntimeException(\sprintf('The file %s cannot be written.', $fileName));
        }

        $con = Propel::getWriteConnection('TheliaMain');
        $con->beginTransaction();

        try {
            // A library image keeps its file name on the image row (TheliaLibrary 2) or on its
            // translation (TheliaLibrary 1): set in the default locale, it lands in the right place.
            $image = new \TheliaLibrary\Model\LibraryImage();
            $image
                ->setLocale($this->shopContext->defaultLocale())
                ->setTitle(pathinfo($file->originalName, \PATHINFO_FILENAME))
                ->setFileName($fileName);

            $dimensions = @getimagesize($directory.$fileName);
            $description = [
                'setMimeType' => $file->mimeType,
                'setFileSize' => filesize($directory.$fileName) ?: null,
                'setWidth' => false !== $dimensions ? $dimensions[0] : null,
                'setHeight' => false !== $dimensions ? $dimensions[1] : null,
            ];

            foreach ($description as $setter => $value) {
                if (method_exists($image, $setter)) {
                    $image->{$setter}($value);
                }
            }

            $image->save($con);
            $con->commit();
        } catch (\Throwable $exception) {
            $con->rollBack();
            @unlink($directory.$fileName);

            throw $exception;
        }

        return $image;
    }

    private static function isExternal(string $source, ?string $shopHost): bool
    {
        if (1 !== preg_match('~^(?:https?:)?//~i', $source)) {
            return false;
        }

        $host = parse_url(str_starts_with($source, '//') ? 'http:'.$source : $source, \PHP_URL_HOST);

        return \is_string($host) && (null === $shopHost || strcasecmp($host, $shopHost) !== 0);
    }

    private static function shopHost(): ?string
    {
        try {
            $host = parse_url(URL::getInstance()->absoluteUrl('/'), \PHP_URL_HOST);
        } catch (\Throwable) {
            return null;
        }

        return \is_string($host) ? $host : null;
    }
}
