<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneBGateArtifactRecorder;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

final class PlanOneBGateRunner
{
    private string $root;

    private PlanOneBGateArtifactRecorder $recorder;

    public function __construct(?string $root = null)
    {
        $resolved = realpath($root ?? dirname(__DIR__, 2));
        if (! is_string($resolved) || ! is_dir($resolved)) {
            throw new RuntimeException('plan_one_b_gate_repository_root_invalid');
        }
        $this->root = rtrim(str_replace('\\', '/', $resolved), '/');
        $this->recorder = new PlanOneBGateArtifactRecorder($this->root);
    }

    public static function execute(array $arguments): int
    {
        try {
            if (count($arguments) !== 2) {
                throw new InvalidArgumentException('plan_one_b_gate_arguments_invalid');
            }
            $runner = new self;
            $runner->assertCleanRepository();
            $runner->run(
                $arguments[1],
                $runner->currentRevision(),
            );

            return 0;
        } catch (Throwable $failure) {
            fwrite(STDERR, $failure->getMessage().PHP_EOL);

            return 1;
        }
    }

    private function run(
        string $gateId,
        string $repositoryRevision,
    ): array {
        $definition = PlanOneBGateArtifactRecorder::definition($gateId);
        $this->ensureOutputDirectories();
        $this->removeStaleFile($this->root.'/'.$definition['producer']['artifact_path']);
        $envelope = $gateId === 'static_analysis'
            ? $this->runStaticAnalysis($definition, $repositoryRevision)
            : $this->runPhpUnit($definition, $repositoryRevision);
        $this->assertCleanRepository();
        if ($this->currentRevision() !== $repositoryRevision) {
            throw new RuntimeException('plan_one_b_gate_repository_changed');
        }
        $this->writeAtomic(
            $this->root.'/'.$definition['producer']['artifact_path'],
            CanonicalJson::encode($envelope)."\n",
        );

        return $envelope;
    }

    private function runPhpUnit(
        array $definition,
        string $repositoryRevision,
    ): array {
        $resultPath = $this->root.'/'.$definition['producer']['result_artifact_path'];
        $this->removeStaleFile($resultPath);
        $measurementPath = $this->prepareMeasurementPaths($definition);
        $process = $this->capture([
            PHP_BINARY,
            'vendor/bin/phpunit',
            ...$definition['producer']['test_paths'],
            '--no-coverage',
            '--log-junit',
            $definition['producer']['result_artifact_path'],
        ], $definition['command']);
        if ($process['exit_code'] !== 0) {
            throw new RuntimeException('plan_one_b_gate_process_failed');
        }
        if ($measurementPath !== null) {
            $this->collectMeasurements($definition, $repositoryRevision, $measurementPath);
        }

        return $this->recorder->recordPhpUnit(
            $definition['gate_id'],
            $process,
            $resultPath,
            $measurementPath,
            $repositoryRevision,
        );
    }

    private function runStaticAnalysis(array $definition, string $repositoryRevision): array
    {
        $resultPath = $this->root.'/'.$definition['producer']['result_artifact_path'];
        $this->removeStaleFile($resultPath);
        $startedAt = $this->now();
        $started = hrtime(true);
        $syntax = [];
        foreach ($definition['producer']['test_paths'] as $path) {
            $syntax[] = [
                'path' => $path,
                ...$this->capture([PHP_BINARY, '-l', $path], 'php -l '.$path),
            ];
        }
        $phpstan = $this->capture([
            PHP_BINARY,
            '-d',
            'memory_limit=1G',
            'vendor/bin/phpstan',
            'analyse',
            '--configuration=phpstan.neon.dist',
            '--error-format=json',
            '--no-progress',
            ...$definition['producer']['test_paths'],
        ], $definition['static_phpstan_command']);
        $machineResult = [
            'schema_version' => '1.0.0',
            'command' => $definition['command'],
            'started_at' => $startedAt,
            'finished_at' => $this->now(),
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'syntax' => $syntax,
            'phpstan' => $phpstan,
        ];
        $this->writeAtomic($resultPath, CanonicalJson::encode($machineResult)."\n");

        return $this->recorder->recordStaticAnalysis($resultPath, $repositoryRevision);
    }

    private function capture(array $arguments, string $command, ?array $environment = null): array
    {
        $startedAt = $this->now();
        $started = hrtime(true);
        $process = new Process($arguments, $this->root, $environment);
        $process->setTimeout(1800);
        $process->run();

        return [
            'command' => $command,
            'exit_code' => $process->getExitCode() ?? 1,
            'started_at' => $startedAt,
            'finished_at' => $this->now(),
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    private function prepareMeasurementPaths(array $definition): ?string
    {
        $relativePath = $definition['producer']['measurement_artifact_path'];
        if ($relativePath === null) {
            return null;
        }
        if (! is_string($relativePath)) {
            throw new InvalidArgumentException('plan_one_b_gate_measurements_invalid');
        }
        $path = $this->root.'/'.$relativePath;
        $this->removeStaleFile($path);
        $this->removeStaleFile($path.'.raw');

        return $path;
    }

    private function collectMeasurements(
        array $definition,
        string $repositoryRevision,
        string $measurementPath,
    ): void {
        $rawPath = $measurementPath.'.raw';
        $nonce = bin2hex(random_bytes(32));
        $command = $definition['producer']['measurement_command'];
        if (! is_string($command)) {
            throw new InvalidArgumentException('plan_one_b_gate_measurements_invalid');
        }
        try {
            $process = $this->capture([
                PHP_BINARY,
                'vendor/bin/phpunit',
                'tests/Unit/Reporting/Evidence/PlanOneBGateArtifactRecorderTest.php',
                '--filter',
                'test_writes_requested_performance_measurements',
                '--no-coverage',
            ], $command, [
                'PLAN_ONE_B_GATE_ID' => $definition['gate_id'],
                'PLAN_ONE_B_GATE_REVISION' => $repositoryRevision,
                'PLAN_ONE_B_GATE_MEASUREMENT_RAW_PATH' => $rawPath,
                'PLAN_ONE_B_GATE_MEASUREMENT_NONCE' => $nonce,
            ]);
            if ($process['exit_code'] !== 0) {
                throw new RuntimeException('plan_one_b_gate_measurements_failed');
            }
            $bytes = $this->readExactFile($rawPath);
            $raw = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($raw)
                || array_is_list($raw)
                || array_keys($raw) !== ['gate_id', 'measurements', 'nonce', 'repository_revision']
                || $raw['gate_id'] !== $definition['gate_id']
                || $raw['repository_revision'] !== $repositoryRevision
                || $raw['nonce'] !== $nonce
                || ! is_array($raw['measurements'])
                || ! array_is_list($raw['measurements'])) {
                throw new InvalidArgumentException('plan_one_b_gate_measurements_invalid');
            }
            $this->writeAtomic($measurementPath, CanonicalJson::encode([
                'schema_version' => '1.0.0',
                'gate_id' => $definition['gate_id'],
                'repository_revision' => $repositoryRevision,
                'nonce' => $nonce,
                'raw_measurements_sha256' => hash('sha256', $bytes),
                'process' => $process,
                'measurements' => $raw['measurements'],
            ])."\n");
        } finally {
            if (is_file($rawPath)) {
                unlink($rawPath);
            }
        }
    }

    private function ensureOutputDirectories(): void
    {
        foreach ([
            'build',
            'build/reports',
            'build/reports/gates',
            'build/reports/gates/results',
        ] as $relative) {
            $path = $this->root.'/'.$relative;
            if (! file_exists($path) && ! mkdir($path)) {
                throw new RuntimeException('plan_one_b_gate_output_directory_failed');
            }
            if (! is_dir($path) || is_link($path)) {
                throw new RuntimeException('plan_one_b_gate_output_directory_invalid');
            }
        }
    }

    private function writeAtomic(string $path, string $bytes): void
    {
        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new RuntimeException('plan_one_b_gate_output_path_invalid');
        }
        $temporary = tempnam(dirname($path), '.plan-1b-gate-');
        if (! is_string($temporary)) {
            throw new RuntimeException('plan_one_b_gate_output_write_failed');
        }
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)
                || ! rename($temporary, $path)
                || ! hash_equals(hash('sha256', $bytes), hash_file('sha256', $path))) {
                throw new RuntimeException('plan_one_b_gate_output_write_failed');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function removeStaleFile(string $path): void
    {
        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new RuntimeException('plan_one_b_gate_output_path_invalid');
        }
        if (is_file($path) && ! unlink($path)) {
            throw new RuntimeException('plan_one_b_gate_output_write_failed');
        }
    }

    private function readExactFile(string $path): string
    {
        $resolved = realpath($path);
        if (is_link($path)
            || ! is_string($resolved)
            || strcasecmp(str_replace('\\', '/', $resolved), str_replace('\\', '/', $path)) !== 0
            || ! is_file($resolved)
            || ! str_starts_with(str_replace('\\', '/', $resolved), $this->root.'/')) {
            throw new InvalidArgumentException('plan_one_b_gate_measurements_invalid');
        }
        $bytes = file_get_contents($resolved);
        if (! is_string($bytes) || $bytes === '') {
            throw new InvalidArgumentException('plan_one_b_gate_measurements_invalid');
        }

        return $bytes;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    private function assertCleanRepository(): void
    {
        $process = new Process(
            ['git', 'status', '--porcelain=v1', '--untracked-files=all', '--ignored=no'],
            $this->root,
        );
        $process->setTimeout(30);
        $process->run();
        if ($process->getExitCode() !== 0 || trim($process->getOutput()) !== '') {
            throw new RuntimeException('plan_one_b_gate_repository_dirty');
        }
    }

    private function currentRevision(): string
    {
        $process = new Process(['git', 'rev-parse', 'HEAD'], $this->root);
        $process->setTimeout(10);
        $process->run();
        $revision = trim($process->getOutput());
        if ($process->getExitCode() !== 0 || preg_match('/^[a-f0-9]{40}$/D', $revision) !== 1) {
            throw new RuntimeException('plan_one_b_gate_repository_revision_invalid');
        }

        return $revision;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(PlanOneBGateRunner::execute($argv));
}
