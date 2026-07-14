<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use Symfony\Component\Process\Process;

final class PendingBenchmarkProcessRegistry
{
    /** @var array<int, Process> */
    private static array $processes = [];

    public static function retainUntilKilled(Process $process): void
    {
        self::reap();
        $pid = $process->getPid();
        if ($pid !== null) {
            self::$processes[$pid] = $process;
        }
    }

    public static function reap(): void
    {
        foreach (self::$processes as $pid => $process) {
            if (! $process->isRunning()) {
                unset(self::$processes[$pid]);
            }
        }
    }
}
