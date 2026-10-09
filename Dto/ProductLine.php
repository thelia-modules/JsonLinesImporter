<?php

declare(strict_types=1);

namespace JsonLinesImporter\Dto;

/**
 * One line of the file, restricted to the data selected at step 2 of the wizard.
 *
 * Collections are Fields of lists: absent = unchanged, null = emptied, list = replaced.
 */
final readonly class ProductLine
{
    public const TEXT_FIELDS = ['title', 'chapo', 'description', 'postscriptum', 'meta_title', 'meta_description', 'meta_keywords'];

    public function __construct(
        public string $ref,
        public Field $visible,
        public Field $virtual,
        public Field $position,
        /** fields of TEXT_FIELDS, the title included whatever the selection */
        public I18nBag $i18n,
        /** whether the title is written on an existing product */
        public bool $titleSelected,
        /** field url, old addresses of the product page */
        public I18nBag $urls,
        public SaleFields $sale,
        /** Field of Label|null */
        public Field $taxRule,
        /** Field of list<CategoryLine>|null */
        public Field $categories,
        /** Field of Label|null */
        public Field $brand,
        /** Field of list<FeatureLine>|null */
        public Field $features,
        /** Field of list<VariantLine>|null */
        public Field $variants,
        /** Field of list<MediaLine>|null */
        public Field $images,
        /** Field of list<MediaLine>|null */
        public Field $documents,
        /** Field of list<VideoLine>|null */
        public Field $videos,
        /** Field of list<AccessoryLine>|null */
        public Field $accessories,
    ) {
    }
}
