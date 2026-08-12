<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Support;

use App\BusinessModules\Addons\EstimateGeneration\Migrations\Support\OnlineSchemaMigrationRuntime;

require_once dirname(__DIR__).'/migrations/support/OnlineSchemaMigrationRuntime.php';

class_alias(OnlineSchemaMigrationRuntime::class, __NAMESPACE__.'\\TrainingBenchmarkOnlineMigrationRuntime');
