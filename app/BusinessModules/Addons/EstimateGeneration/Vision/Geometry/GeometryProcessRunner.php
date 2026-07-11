<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\GeometryExtractionException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final readonly class GeometryProcessRunner
{
    /** @param array<int, string> $command @return array{exit_code: int|null, stdout: string, stderr: string} */
    public function run(
        array $command,
        string $workspace,
        string $errorPrefix,
        int $timeoutSeconds,
        int $maxOutputBytes,
        int $maxErrorBytes = 8192,
    ): array {
        $sandbox = getenv('GEOMETRY_SANDBOX_BINARY');
        if (PHP_OS_FAMILY === 'Linux' && is_string($sandbox) && is_executable($sandbox)) {
            return $this->runSandboxed($sandbox, $command, $workspace, $errorPrefix, $timeoutSeconds, $maxOutputBytes, $maxErrorBytes);
        }

        return $this->runBounded($command, $workspace, $errorPrefix, $timeoutSeconds, $maxOutputBytes, $maxErrorBytes);
    }

    /** @param array<int, string> $command @return array{exit_code: int|null, stdout: string, stderr: string} */
    private function runBounded(array $command, string $workspace, string $prefix, int $timeout, int $maxOutput, int $maxError): array
    {
        $stdout = '';
        $stderr = '';
        $process = new Process($command, $workspace);
        $process->setTimeout($timeout);
        $process->setIdleTimeout(min($timeout, 15));
        try {
            $process->run(function (string $type, string $buffer) use (&$stdout, &$stderr, $maxOutput, $maxError, $prefix, $process): void {
                if ($type === Process::OUT) {
                    $stdout .= $buffer;
                    $process->clearOutput();
                    if (strlen($stdout) > $maxOutput) {
                        throw new GeometryExtractionException($prefix.'_runtime_output_oversize');
                    }

                    return;
                }
                $stderr .= $buffer;
                $process->clearErrorOutput();
                if (strlen($stderr) > $maxError) {
                    throw new GeometryExtractionException($prefix.'_runtime_output_oversize');
                }
            });
        } catch (ProcessTimedOutException) {
            throw new GeometryExtractionException($prefix.'_runtime_timeout', true);
        } catch (GeometryExtractionException $exception) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
            throw $exception;
        } catch (\Throwable) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
            throw new GeometryExtractionException($prefix.'_runtime_failed');
        }

        return ['exit_code' => $process->getExitCode(), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @param array<int, string> $command @return array{exit_code: int|null, stdout: string, stderr: string} */
    private function runSandboxed(string $sandbox, array $command, string $workspace, string $prefix, int $timeout, int $maxOutput, int $maxError): array
    {
        $stdoutPath = $workspace.DIRECTORY_SEPARATOR.'process.stdout';
        $stderrPath = $workspace.DIRECTORY_SEPARATOR.'process.stderr';
        $fileBlocks = (int) ceil(max($maxOutput, $maxError) / 512);
        $sandboxCommand = [$sandbox, $workspace, $stdoutPath, $stderrPath, (string) $timeout, '524288', (string) $timeout, (string) $fileBlocks, '64', ...$command];
        $process = new Process($sandboxCommand, $workspace);
        $process->disableOutput();
        $process->setTimeout($timeout + 2);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new GeometryExtractionException($prefix.'_runtime_timeout', true);
        } catch (\Throwable) {
            throw new GeometryExtractionException($prefix.'_runtime_failed');
        }
        if (in_array($process->getExitCode(), [124, 137], true)) {
            throw new GeometryExtractionException($prefix.'_runtime_timeout', true);
        }
        if ($process->getExitCode() === 153) {
            throw new GeometryExtractionException($prefix.'_runtime_output_oversize');
        }
        $stdoutSize = is_file($stdoutPath) ? filesize($stdoutPath) : 0;
        $stderrSize = is_file($stderrPath) ? filesize($stderrPath) : 0;
        if (! is_int($stdoutSize) || ! is_int($stderrSize) || $stdoutSize > $maxOutput || $stderrSize > $maxError) {
            throw new GeometryExtractionException($prefix.'_runtime_output_oversize');
        }

        return [
            'exit_code' => $process->getExitCode(),
            'stdout' => is_file($stdoutPath) ? (string) file_get_contents($stdoutPath) : '',
            'stderr' => is_file($stderrPath) ? (string) file_get_contents($stderrPath) : '',
        ];
    }
}
