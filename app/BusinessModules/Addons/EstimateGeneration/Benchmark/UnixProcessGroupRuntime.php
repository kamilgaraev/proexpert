<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use Closure;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final readonly class UnixProcessGroupRuntime
{
    private Closure $findExecutable;

    public function __construct(?Closure $findExecutable = null)
    {
        $finder = new ExecutableFinder;
        $this->findExecutable = $findExecutable
            ?? static fn (string $name): ?string => $finder->find($name);
    }

    public function assertAvailable(): void
    {
        $this->setsidBinary();

        if (! function_exists('posix_kill')) {
            $this->killBinary();
        }
    }

    public function setsidBinary(): string
    {
        return $this->executable('setsid');
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    public function wrap(array $command): array
    {
        return [$this->setsidBinary(), ...$command];
    }

    public function terminate(Process $process, int $graceMicroseconds): bool
    {
        $pid = $process->getPid();
        if ($pid === null) {
            return ! $process->isRunning();
        }

        if ($this->groupExists($pid) && ! $this->signalGroup($pid, 15)) {
            return false;
        }
        $this->waitForGroupExit($process, $pid, $graceMicroseconds);

        if ($this->groupExists($pid) && ! $this->signalGroup($pid, 9)) {
            return false;
        }
        $this->waitForGroupExit($process, $pid, $graceMicroseconds);

        return ! $this->groupExists($pid);
    }

    private function signalGroup(int $pid, int $signal): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill(-$pid, $signal);
        }

        $process = new Process([$this->killBinary(), '-'.$signal, '--', '-'.$pid]);
        $process->disableOutput();
        $process->run();

        return $process->isSuccessful();
    }

    private function killBinary(): string
    {
        return $this->executable('kill');
    }

    private function groupExists(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill(-$pid, 0);
        }

        $process = new Process([$this->killBinary(), '-0', '--', '-'.$pid]);
        $process->disableOutput();
        $process->run();

        return $process->isSuccessful();
    }

    private function executable(string $name): string
    {
        $path = ($this->findExecutable)($name);
        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_executable($path)) {
            throw new BenchmarkContractException('worker_process_group_unavailable');
        }

        return $path;
    }

    private function waitForGroupExit(Process $process, int $pid, int $graceMicroseconds): void
    {
        $deadline = hrtime(true) + max(1, $graceMicroseconds) * 1_000;
        while ($this->groupExists($pid) && hrtime(true) < $deadline) {
            $process->isRunning();
            usleep(5_000);
        }
        $process->isRunning();
    }
}
