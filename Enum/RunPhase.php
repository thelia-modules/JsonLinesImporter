<?php

declare(strict_types=1);

namespace JsonLinesImporter\Enum;

enum RunPhase: string
{
    case LINES = 'lines';
    case ACCESSORIES = 'accessories';
    case FINISHED = 'finished';
}
