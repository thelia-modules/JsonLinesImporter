<?php

declare(strict_types=1);

namespace JsonLinesImporter\Enum;

enum VideoDestination: string
{
    case BLOCKS = 'blocks';
    case FEATURE = 'feature';
}
