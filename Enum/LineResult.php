<?php

declare(strict_types=1);

namespace JsonLinesImporter\Enum;

enum LineResult: string
{
    case PENDING = 'pending';
    case INVALID = 'invalid';
    case EXCLUDED = 'excluded';
    case CREATED = 'created';
    case UPDATED = 'updated';
    case ERROR = 'error';
}
