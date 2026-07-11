<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

enum CheckpointStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
