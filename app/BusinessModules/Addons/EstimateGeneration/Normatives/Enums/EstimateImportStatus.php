<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums;

enum EstimateImportStatus: string
{
    case CREATED = 'created';
    case IMPORTING = 'importing';
    case PARSED = 'parsed';
    case FAILED = 'failed';
}
