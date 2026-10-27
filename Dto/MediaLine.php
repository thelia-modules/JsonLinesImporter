<?php

declare(strict_types=1);

namespace JsonLinesImporter\Dto;

/**
 * An image or a document of the product.
 *
 * The file of an image is translated: the root source is the file of every active language, the source
 * of an `i18n.<locale>` block wins over it for its language. A document has one file, its root source.
 */
final readonly class MediaLine
{
    public const TEXT_FIELDS = ['title', 'chapo', 'description', 'postscriptum'];

    /**
     * @param array<string, string> $localeSources locale => source, restricted to the selected locales
     * @param string                $identity      the root source, else the first source of the i18n
     *                                             blocks: matches the media from one import to the next
     */
    public function __construct(
        public ?string $key,
        public ?string $source,
        public Field $visible,
        public ?int $position,
        public I18nBag $i18n,
        public array $localeSources = [],
        public string $identity = '',
    ) {
    }

    /**
     * @param list<string> $activeLocales
     *
     * @return array<string, string> locale => source of its file
     */
    public function filesByLocale(array $activeLocales): array
    {
        $files = null === $this->source ? [] : array_fill_keys($activeLocales, $this->source);

        return array_replace($files, $this->localeSources);
    }

    public function withI18n(I18nBag $i18n): self
    {
        return new self($this->key, $this->source, $this->visible, $this->position, $i18n, $this->localeSources, $this->identity);
    }
}
