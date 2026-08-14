<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;

abstract class DatabaseLessTestCase extends TestCase
{
    public function createApplication()
    {
        $isEstimateGeneration = str_starts_with(static::class, 'Tests\\Unit\\EstimateGeneration\\');
        $previous = getenv('ESTIMATE_GENERATION_MODULAR_CONTRACT_BOOTSTRAP');
        if ($isEstimateGeneration) {
            putenv('ESTIMATE_GENERATION_MODULAR_CONTRACT_BOOTSTRAP=1');
        }
        try {
            $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
            $app->make(Kernel::class)->bootstrap();
        } finally {
            if ($isEstimateGeneration) {
                $previous === false
                    ? putenv('ESTIMATE_GENERATION_MODULAR_CONTRACT_BOOTSTRAP')
                    : putenv('ESTIMATE_GENERATION_MODULAR_CONTRACT_BOOTSTRAP='.$previous);
            }
        }

        return $app;
    }
}
