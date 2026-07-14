<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use Symfony\Component\Process\Process;
use Throwable;

final readonly class ProcessBenchmarkCaseExecutor implements BenchmarkCaseExecutor
{
    private const TERMINATION_GRACE_MICROSECONDS = 100_000;

    private const WINDOWS_TERMINATION_GRACE_MICROSECONDS = 1_000_000;

    private UnixProcessGroupRuntime $unixRuntime;

    private WindowsProcessTreeRuntime $windowsRuntime;

    public function __construct(
        private string $phpBinary,
        private string $artisanPath,
        private int $maxOutputBytes = 1_048_576,
        private string $memoryLimit = '128M',
        ?UnixProcessGroupRuntime $unixRuntime = null,
        ?WindowsProcessTreeRuntime $windowsRuntime = null,
    ) {
        if (! is_file($phpBinary) || ! is_file($artisanPath)
            || $maxOutputBytes < 1024 || $maxOutputBytes > 16_777_216
            || ! preg_match('/^(?:64|96|128|192|256)M$/', $memoryLimit)) {
            throw new BenchmarkContractException('process_executor_config_invalid');
        }

        $this->unixRuntime = $unixRuntime ?? new UnixProcessGroupRuntime;
        $this->windowsRuntime = $windowsRuntime ?? new WindowsProcessTreeRuntime;
    }

    public function execute(
        BenchmarkCaseExecutionRequest $request,
        BenchmarkCaseData $case,
        BenchmarkPipelineAdapter $adapter,
    ): BenchmarkPipelineResultData {
        PendingBenchmarkProcessRegistry::reap();
        $command = [
            $this->phpBinary,
            '-d', 'memory_limit='.$this->memoryLimit,
            $this->artisanPath,
            'estimate-generation:benchmark-case',
            '--manifest-ref='.$request->manifestReference,
            '--case-id='.$request->caseId,
            '--adapter='.$request->adapterId,
        ];
        if (PHP_OS_FAMILY !== 'Windows') {
            try {
                $this->unixRuntime->assertAvailable();
                $command = $this->unixRuntime->wrap($command);
            } catch (BenchmarkContractException $exception) {
                return BenchmarkPipelineResultData::technicalFailure($exception->reason);
            }
        }
        $process = new Process($command, dirname($this->artisanPath), timeout: null);
        if (PHP_OS_FAMILY === 'Windows') {
            $process->setOptions(['create_process_group' => true]);
        }
        $stdout = '';
        $stderrBytes = 0;
        $deadline = hrtime(true) + $request->timeoutMs * 1_000_000;
        try {
            $process->start();
            while ($process->isRunning()) {
                if (hrtime(true) >= $deadline) {
                    if (! $this->terminateTree($process)) {
                        return BenchmarkPipelineResultData::technicalFailure('worker_process_group_termination_failed');
                    }

                    return BenchmarkPipelineResultData::technicalFailure('case_timeout');
                }
                $stdout .= $process->getIncrementalOutput();
                $stderrBytes += strlen($process->getIncrementalErrorOutput());
                if (strlen($stdout) + $stderrBytes > $this->maxOutputBytes) {
                    if (! $this->terminateTree($process)) {
                        return BenchmarkPipelineResultData::technicalFailure('worker_process_group_termination_failed');
                    }

                    return BenchmarkPipelineResultData::technicalFailure('worker_output_limit');
                }
                usleep(10_000);
            }
            $stdout .= $process->getIncrementalOutput();
            $stderrBytes += strlen($process->getIncrementalErrorOutput());
        } catch (Throwable) {
            if (! $this->terminateTree($process)) {
                return BenchmarkPipelineResultData::technicalFailure('worker_process_group_termination_failed');
            }

            return BenchmarkPipelineResultData::technicalFailure('worker_exception');
        }
        if (strlen($stdout) + $stderrBytes > $this->maxOutputBytes) {
            return BenchmarkPipelineResultData::technicalFailure('worker_output_limit');
        }
        if (! $process->isSuccessful()) {
            return BenchmarkPipelineResultData::technicalFailure('worker_failed');
        }

        return BenchmarkPipelineResultData::fromProtocolJson(trim($stdout));
    }

    private function terminateTree(Process $process): bool
    {
        $pid = $process->getPid();
        if ($pid !== null && PHP_OS_FAMILY === 'Windows') {
            $terminated = $this->windowsRuntime->terminate($process, self::WINDOWS_TERMINATION_GRACE_MICROSECONDS);
            PendingBenchmarkProcessRegistry::retainUntilKilled($process);

            return $terminated;
        }
        if ($pid !== null && PHP_OS_FAMILY !== 'Windows') {
            return $this->unixRuntime->terminate($process, self::TERMINATION_GRACE_MICROSECONDS);
        }
        if ($process->isRunning()) {
            $process->stop(0.0);
        }

        return ! $process->isRunning();
    }
}
