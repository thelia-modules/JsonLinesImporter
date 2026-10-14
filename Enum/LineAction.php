<?php

declare(strict_types=1);

namespace JsonLinesImporter\Enum;

enum LineAction: string
{
    case CREATE = 'create';
    case UPDATE = 'update';
}
