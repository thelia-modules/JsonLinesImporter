<?php

declare(strict_types=1);

namespace JsonLinesImporter\Dto;

/**
 * A referential element given by its title: `{"title": ...}` and/or `{"i18n": {"<locale>": {"title": ...}}}`.
 */
final readonly class Label
{
    public function __construct(
        public I18nBag $titles,
        public string $matchingTitle,
    ) {
    }

    /**
     * The matching title is the title of the default locale of the shop, else the first locale of the block.
     *
     * @param array<string, mixed> $node
     */
    public static function fromNode(array $node, string $defaultLocale): self
    {
        $titles = I18nBag::fromNode($node, ['title'], $defaultLocale);

        return new self($titles, (string) $titles->firstValue('title', $defaultLocale));
    }

    public static function fromTitle(string $title, string $defaultLocale): self
    {
        return new self(new I18nBag([$defaultLocale => ['title' => $title]]), $title);
    }

    public function normalizedTitle(): string
    {
        return self::normalize($this->matchingTitle);
    }

    public static function normalize(string $title): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $title)));
    }
}
