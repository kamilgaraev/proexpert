<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use Closure;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class WindowsProcessTreeRuntime
{
    private Closure $commandRunner;

    public function __construct(?Closure $commandRunner = null)
    {
        $this->commandRunner = $commandRunner ?? $this->runCommand(...);
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

    public function terminatePid(int $pid, Closure $isRunning, int $budgetMicroseconds): bool
    {
        if ($pid < 1 || $budgetMicroseconds < 1) {
            return false;
        }

        try {
            $deadline = hrtime(true) + min(2_000_000, $budgetMicroseconds) * 1_000;
            $command = ['taskkill', '/PID', (string) $pid, '/T', '/F'];
            $exitCode = ($this->commandRunner)($command, $this->remainingMicroseconds($deadline));
            if ($exitCode !== 0) {
                return false;
            }

            while (true) {
                if (! $isRunning()) {
                    return true;
                }
                $remaining = $this->remainingMicroseconds($deadline);
                if ($remaining < 1) {
                    return false;
                }
                usleep(min(5_000, $remaining));
            }
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function runCommand(array $command, int $timeoutMicroseconds): ?int
    {
        if ($timeoutMicroseconds < 1) {
            return null;
        }

        try {
            $killer = new Process($command, timeout: null);
            $killer->disableOutput();
            $killer->start();
            $deadline = hrtime(true) + $timeoutMicroseconds * 1_000;
            while ($killer->isRunning() && hrtime(true) < $deadline) {
                usleep(min(5_000, $this->remainingMicroseconds($deadline)));
            }
            if ($killer->isRunning()) {
                PendingBenchmarkProcessRegistry::retainUntilKilled($killer);

                return null;
            }

            return $killer->getExitCode();
        } catch (Throwable) {
            return null;
        }
    }

    private function remainingMicroseconds(int $deadline): int
    {
        return max(0, (int) floor(($deadline - hrtime(true)) / 1_000));
    }
}
