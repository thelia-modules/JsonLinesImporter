<?php

declare(strict_types=1);

namespace JsonLinesImporter\Enum;

enum ImportStatus: string
{
    case ANALYSING = 'analysing';
    case ANALYSED = 'analysed';
    case RUNNING = 'running';
    case PAUSED = 'paused';
    case DONE = 'done';
}
