<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class ProcessBenchmarkCaseExecutor implements BenchmarkCaseExecutor
{
    public function __construct(
        private string $phpBinary,
        private string $artisanPath,
        private int $maxOutputBytes = 1_048_576,
        private string $memoryLimit = '128M',
    ) {
        if (! is_file($phpBinary) || ! is_file($artisanPath)
            || $maxOutputBytes < 1024 || $maxOutputBytes > 16_777_216
            || ! preg_match('/^(?:64|96|128|192|256)M$/', $memoryLimit)) {
            throw new BenchmarkContractException('process_executor_config_invalid');
        }
    }

    public function execute(
        BenchmarkCaseExecutionRequest $request,
        BenchmarkCaseData $case,
        BenchmarkPipelineAdapter $adapter,
    ): BenchmarkPipelineResultData {
        $command = [
            $this->phpBinary,
            '-d', 'memory_limit='.$this->memoryLimit,
            $this->artisanPath,
            'estimate-generation:benchmark-case',
            '--manifest-ref='.$request->manifestReference,
            '--case-id='.$request->caseId,
            '--adapter='.$request->adapterId,
        ];
        if (PHP_OS_FAMILY !== 'Windows' && is_executable('/usr/bin/setsid')) {
            array_unshift($command, '/usr/bin/setsid');
        }
        $process = new Process($command, dirname($this->artisanPath), timeout: $request->timeoutMs / 1000);
        $stdout = '';
        $stderrBytes = 0;
        try {
            $process->start();
            while ($process->isRunning()) {
                $process->checkTimeout();
                $stdout .= $process->getIncrementalOutput();
                $stderrBytes += strlen($process->getIncrementalErrorOutput());
                if (strlen($stdout) + $stderrBytes > $this->maxOutputBytes) {
                    $this->terminateTree($process);

                    return BenchmarkPipelineResultData::technicalFailure('worker_output_limit');
                }
                usleep(10_000);
            }
            $stdout .= $process->getIncrementalOutput();
            $stderrBytes += strlen($process->getIncrementalErrorOutput());
        } catch (ProcessTimedOutException) {
            $this->terminateTree($process);

            return BenchmarkPipelineResultData::technicalFailure('case_timeout');
        } catch (Throwable) {
            $this->terminateTree($process);

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

    private function terminateTree(Process $process): void
    {
        $pid = $process->getPid();
        if ($pid !== null && PHP_OS_FAMILY === 'Windows') {
            (new Process(['taskkill', '/PID', (string) $pid, '/T', '/F']))->run();
        }
        if ($pid !== null && PHP_OS_FAMILY !== 'Windows' && function_exists('posix_kill')) {
            @posix_kill(-$pid, SIGKILL);
        }
        if ($process->isRunning()) {
            $process->stop(0.0);
        }
    }
}
