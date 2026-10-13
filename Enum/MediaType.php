<?php

declare(strict_types=1);

namespace JsonLinesImporter\Enum;

enum MediaType: string
{
    case PRODUCT_IMAGE = 'product_image';
    case PRODUCT_DOCUMENT = 'product_document';
    case PRODUCT_VIDEO = 'product_video';
    case LIBRARY_IMAGE = 'library_image';
}
