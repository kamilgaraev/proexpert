<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use Closure;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class WindowsProcessTreeRuntime
{
    private const TASKKILL_LAUNCH_TIMEOUT_SECONDS = 1.0;

    private Closure $taskkill;

    public function __construct(?Closure $taskkill = null)
    {
        $this->taskkill = $taskkill ?? $this->runTaskkill(...);
    }

    public function terminate(Process $process, int $graceMicroseconds): bool
    {
        $pid = $process->getPid();
        if ($pid === null) {
            return ! $process->isRunning();
        }

        $terminated = $this->terminatePid(
            $pid,
            static fn (): bool => $process->isRunning(),
            $graceMicroseconds,
        );

        return $terminated;
    }

    public function terminatePid(int $pid, Closure $isRunning, int $graceMicroseconds): bool
    {
        if ($pid < 1) {
            return false;
        }

        try {
            if (! ($this->taskkill)($pid)) {
                return false;
            }

            $deadline = hrtime(true) + min(2_000_000, max(1, $graceMicroseconds)) * 1_000;
            while ($isRunning() && hrtime(true) < $deadline) {
                usleep(5_000);
            }

            return ! $isRunning();
        } catch (Throwable) {
            return false;
        }
    }

    private function runTaskkill(int $pid): bool
    {
        try {
            $killer = new Process(
                ['cmd', '/D', '/C', 'start', '', '/B', 'taskkill', '/PID', (string) $pid, '/T', '/F'],
                timeout: self::TASKKILL_LAUNCH_TIMEOUT_SECONDS,
            );
            $killer->disableOutput();
            $killer->run();

            return $killer->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }
}
