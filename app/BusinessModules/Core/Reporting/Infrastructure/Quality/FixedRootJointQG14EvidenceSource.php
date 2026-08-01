<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Quality;

use App\BusinessModules\Core\Reporting\Domain\Contracts\JointQG14EvidenceSource;
use App\BusinessModules\Core\Reporting\Domain\DTO\JointQG14Evidence;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use RuntimeException;

final class FixedRootJointQG14EvidenceSource implements JointQG14EvidenceSource
{
    /** @var null|\Closure(list<string>): array{0:int, 1:string, 2:string} */
    private readonly ?\Closure $runner;

    /** @param null|callable(list<string>): array{0:int, 1:string, 2:string} $runner */
    public function __construct(private readonly string $adminRoot, private readonly string $backendRoot, ?callable $runner = null)
    {
        if ($adminRoot === '' || $backendRoot === '' || $adminRoot === $backendRoot) {
            throw new RuntimeException('joint-qg14:invalid-root');
        }

        $this->runner = $runner === null ? null : \Closure::fromCallable($runner);
    }

    public function execute(): JointQG14Evidence
    {
        $argv = [
            'node',
            'scripts/verify-reporting-cutover.mjs',
            '--admin-root=' . $this->adminRoot,
            '--backend-root=' . $this->backendRoot,
        ];
        [$exitCode, $stdout, $stderr] = $this->runner === null ? $this->run($argv) : ($this->runner)($argv);

        if ($exitCode !== 0 || $stderr !== '' || str_contains($stdout, "\n") || $stdout === '') {
            throw new RuntimeException('joint-qg14:invalid-output');
        }

        try {
            $data = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('joint-qg14:invalid-output');
        }

        $expectedKeys = ['admin_forbidden_symbol_matches', 'backend_forbidden_symbol_matches', 'combined_forbidden_symbol_matches', 'qg14_admin_sha256', 'qg14_backend_sha256', 'qg14_combined_sha256'];
        if (! is_array($data) || array_keys($data) !== $expectedKeys) {
            throw new RuntimeException('joint-qg14:invalid-output');
        }

        try {
            return new JointQG14Evidence(
                $this->integer($data['admin_forbidden_symbol_matches']),
                $this->integer($data['backend_forbidden_symbol_matches']),
                $this->integer($data['combined_forbidden_symbol_matches']),
                new Sha256Hash($this->string($data['qg14_admin_sha256'])),
                new Sha256Hash($this->string($data['qg14_backend_sha256'])),
                new Sha256Hash($this->string($data['qg14_combined_sha256'])),
                $argv,
                'qg14_forbidden_symbols',
            );
        } catch (\InvalidArgumentException) {
            throw new RuntimeException('joint-qg14:invalid-output');
        }
    }

    /** @param list<string> $argv @return array{0:int, 1:string, 2:string} */
    private function run(array $argv): array
    {
        $command = implode(' ', array_map('escapeshellarg', $argv));
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('joint-qg14:invalid-output');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function integer(mixed $value): int
    {
        if (! is_int($value)) {
            throw new \InvalidArgumentException();
        }

        return $value;
    }

    private function string(mixed $value): string
    {
        if (! is_string($value)) {
            throw new \InvalidArgumentException();
        }

        return $value;
    }
}
