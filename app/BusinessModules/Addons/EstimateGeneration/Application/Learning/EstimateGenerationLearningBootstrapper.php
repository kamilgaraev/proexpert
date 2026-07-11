<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Learning;

interface EstimateGenerationLearningBootstrapper
{
    /** @param array<string, mixed> $options @return array<string, int|bool> */
    public function bootstrap(array $options = []): array;
}
