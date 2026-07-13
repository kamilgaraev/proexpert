<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

enum AdminSessionOperation: string
{
    case Retry = 'retry';
    case Cancel = 'cancel';
    case Archive = 'archive';
}
