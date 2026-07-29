<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance;

use InvalidArgumentException;

final readonly class ProjectControlMetricContract
{
    public const VERSION = 'project_control_core.v1';

    public const CODES = ['bac', 'pv', 'ev', 'ac', 'spi', 'cpi', 'eac'];

    public function version(): string
    {
        return self::VERSION;
    }

    public function assertCompatible(string $version, array $metrics): void
    {
        if ($version !== self::VERSION || array_values($metrics) !== self::CODES) {
            throw new InvalidArgumentException('project_control_metric_contract_invalid');
        }
    }
}
