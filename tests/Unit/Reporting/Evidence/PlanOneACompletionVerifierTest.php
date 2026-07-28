<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Evidence;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneACompletionVerifier;
use DateTimeImmutable;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;

final class PlanOneACompletionVerifierTest extends TestCase
{
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            $this->removeTree($directory);
        }
    }

    public function test_accepts_the_exact_repository_relative_production_invocation(): void
    {
        $reference = (new PlanOneACompletionVerifier())->assertReady(
            'docs/reports/contracts/plan-1a-contract-lock.json',
            'docs/reports/contracts/plan-1a-completion.schema.json',
            'build/reports/plan-1a-completion.json',
            'docs/reports/contracts/plan-1a-gate-evidence.schema.json',
            'build/reports/plan-1a-ci-authorization.json',
        );

        self::assertSame(hash_file('sha256', $this->root().'/docs/reports/contracts/plan-1a-contract-lock.json'), $reference->lockSha256);
    }

    public function test_accepts_only_the_canonical_five_file_handoff_and_returns_raw_digests(): void
    {
        $repository = $this->repository();
        $paths = $this->paths($repository);

        $reference = $this->verify($repository);

        self::assertSame(hash_file('sha256', $paths['lock']), $reference->lockSha256);
        self::assertSame(hash_file('sha256', $paths['completionArtifact']), $reference->evidenceSha256);
        self::assertEquals(new DateTimeImmutable('2026-07-28T14:00:00Z'), $reference->generatedAt);
        self::assertSame('passed', $reference->status);
    }

    public function test_rejects_each_missing_input_file(): void
    {
        foreach (array_keys($this->paths($this->repository())) as $key) {
            $repository = $this->repository();
            $paths = $this->paths($repository);
            unlink($paths[$key]);

            $this->assertRejected(fn () => $this->verify($repository));
        }
    }

    public function test_rejects_invalid_completion_and_lock_bindings(): void
    {
        $mutations = [
            'malformed completion schema' => static function (string $repository): void {
                file_put_contents($repository.'/docs/reports/contracts/plan-1a-completion.schema.json', '{"type":');
            },
            'malformed digest' => function (string $repository): void {
                $this->mutateCompletion($repository, static function (array &$completion): void {
                    $completion['contract_lock_sha256'] = 'bad';
                });
            },
            'failed evidence' => function (string $repository): void {
                $this->mutateCompletion($repository, static function (array &$completion): void {
                    $completion['status'] = 'failed';
                });
            },
            'another lock digest' => function (string $repository): void {
                $this->mutateCompletion($repository, static function (array &$completion): void {
                    $completion['contract_lock_sha256'] = str_repeat('0', 64);
                });
            },
            'mutated lock bytes' => static function (string $repository): void {
                file_put_contents($repository.'/docs/reports/contracts/plan-1a-contract-lock.json', "\n", FILE_APPEND);
            },
            'wrong authorization mode' => function (string $repository): void {
                $this->mutateCompletion($repository, static function (array &$completion): void {
                    $completion['ci_http_matrices']['authorization']['verification_mode'] = 'production_topology_snapshot';
                });
            },
        ];

        foreach ($mutations as $label => $mutation) {
            $repository = $this->repository();
            $mutation($repository);
            $this->assertRejected(
                fn () => $this->verify($repository),
                $label,
            );
        }
    }

    public function test_rejects_every_noncanonical_explicit_path_and_alias(): void
    {
        $repository = $this->repository();
        $paths = $this->paths($repository);

        foreach ([
            'lock' => 'docs/reports/contracts/../contracts/plan-1a-contract-lock.json',
            'completionSchema' => 'docs/reports/contracts/../contracts/plan-1a-completion.schema.json',
            'completionArtifact' => 'build/reports/../reports/plan-1a-completion.json',
            'authorizationSchema' => 'docs/reports/contracts/../contracts/plan-1a-gate-evidence.schema.json',
            'authorizationArtifact' => 'build/reports/../reports/plan-1a-ci-authorization.json',
        ] as $key => $alias) {
            $arguments = $this->relativePaths();
            $arguments[$key] = $alias;
            $this->assertRejected(
                fn () => $this->verify($repository, $arguments),
                $key,
            );
        }

        foreach (['authorizationSchema', 'authorizationArtifact'] as $key) {
            $alternate = $repository.'/alternate-'.basename($paths[$key]);
            copy($paths[$key], $alternate);
            $arguments = $this->relativePaths();
            $arguments[$key] = basename($alternate);
            $this->assertRejected(
                fn () => $this->verify($repository, $arguments),
                $key,
            );
        }

        foreach ($paths as $key => $absolute) {
            $arguments = $this->relativePaths();
            $arguments[$key] = $absolute;
            $this->assertRejected(
                fn () => $this->verify($repository, $arguments),
                'absolute '.$key,
            );
        }
    }

    public function test_rejects_reparse_point_escape_for_gate_schema_and_authorization_artifact(): void
    {
        foreach ([
            'authorizationSchema' => 'docs/reports/contracts',
            'authorizationArtifact' => 'build/reports',
        ] as $key => $relativeDirectory) {
            $repository = $this->repository();
            $paths = $this->paths($repository);
            $junction = $repository.'/'.$relativeDirectory;
            $target = $repository.'/outside-'.str_replace('/', '-', $relativeDirectory);
            rename($junction, $target);
            $process = new Process([
                'powershell',
                '-NoProfile',
                '-Command',
                "New-Item -ItemType Junction -Path '".str_replace("'", "''", $junction)."' -Target '".str_replace("'", "''", $target)."' | Out-Null",
            ]);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

            try {
                $this->assertRejected(
                    fn () => $this->verify($repository),
                    $key,
                );
            } finally {
                rmdir($junction);
                rename($target, $junction);
            }
        }
    }

    public function test_rejects_gate_schema_not_equal_to_the_commit_blob(): void
    {
        $repository = $this->repository();
        $paths = $this->paths($repository);
        file_put_contents($paths['authorizationSchema'], "\n", FILE_APPEND);

        $this->assertRejected(fn () => $this->verify($repository));
    }

    public function test_rejects_gate_schema_absent_from_the_completion_commit(): void
    {
        $repository = $this->repository(false);
        $paths = $this->paths($repository);

        $this->assertRejected(fn () => $this->verify($repository));
    }

    public function test_rejects_every_authorization_contract_mutation_after_rebinding_the_raw_digest(): void
    {
        $mutations = [
            'malformed bytes' => static function (array &$artifact): void {
                $artifact = ['malformed'];
            },
            'wrong root status' => static function (array &$artifact): void {
                $artifact['status'] = 'failed';
            },
            'wrong mode' => static function (array &$artifact): void {
                $artifact['verification_mode'] = 'production_topology_snapshot';
            },
            'missing record' => static function (array &$artifact): void {
                array_pop($artifact['cases']);
            },
            'duplicate record' => static function (array &$artifact): void {
                $artifact['cases'][1] = $artifact['cases'][0];
            },
            'extra record' => static function (array &$artifact): void {
                $artifact['cases'][] = $artifact['cases'][0];
            },
            'reordered records' => static function (array &$artifact): void {
                [$artifact['cases'][0], $artifact['cases'][1]] = [$artifact['cases'][1], $artifact['cases'][0]];
            },
            'wrong integer status' => static function (array &$artifact): void {
                $artifact['cases'][0]['status'] = 403;
            },
            'unknown record property' => static function (array &$artifact): void {
                $artifact['cases'][0]['unexpected'] = true;
            },
            'case count mismatch' => static function (array &$artifact): void {
                $artifact['counts']['cases'] = 21;
            },
            'passed count mismatch' => static function (array &$artifact): void {
                $artifact['counts']['passed'] = 21;
            },
            'allowed count mismatch' => static function (array &$artifact): void {
                $artifact['counts']['allowed_cases'] = 8;
            },
            'denied count mismatch' => static function (array &$artifact): void {
                $artifact['counts']['denied_cases'] = 14;
            },
            'request count mismatch' => static function (array &$artifact): void {
                $artifact['counts']['http_requests'] = 27;
            },
            'assertion count mismatch' => static function (array &$artifact): void {
                $artifact['counts']['assertions'] = 131;
            },
            'folded requests mismatch' => static function (array &$artifact): void {
                $artifact['cases'][0]['request_count'] = 2;
            },
            'folded assertions mismatch' => static function (array &$artifact): void {
                $artifact['cases'][0]['assertions'] = 5;
            },
        ];

        foreach ($mutations as $label => $mutation) {
            $repository = $this->repository();
            $artifact = $this->decode($repository.'/build/reports/plan-1a-ci-authorization.json');
            $mutation($artifact);
            $this->writeJson($repository.'/build/reports/plan-1a-ci-authorization.json', $artifact);
            $this->bindAuthorizationDigest($repository);
            $this->assertRejected(
                fn () => $this->verify($repository),
                $label,
            );
        }
    }

    public function test_rejects_authorization_bytes_when_completion_digest_is_not_rebound(): void
    {
        $repository = $this->repository();
        $paths = $this->paths($repository);
        file_put_contents($paths['authorizationArtifact'], "\n", FILE_APPEND);

        $this->assertRejected(fn () => $this->verify($repository));
    }

    private function repository(bool $trackGateSchema = true): string
    {
        $repository = $this->temporaryDirectory();
        foreach ([
            'docs/reports/contracts/plan-1a-contract-lock.json',
            'docs/reports/contracts/plan-1a-completion.schema.json',
            'docs/reports/contracts/plan-1a-gate-evidence.schema.json',
            'build/reports/plan-1a-completion.json',
            'build/reports/plan-1a-ci-authorization.json',
        ] as $path) {
            $target = $repository.'/'.$path;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            copy($this->root().'/'.$path, $target);
        }

        $this->git($repository, ['init']);
        $this->git($repository, ['config', 'user.email', 'reports@example.test']);
        $this->git($repository, ['config', 'user.name', 'Reports Test']);
        file_put_contents($repository.'/seed.txt', 'seed');
        $tracked = ['seed.txt'];
        if ($trackGateSchema) {
            $tracked[] = 'docs/reports/contracts/plan-1a-gate-evidence.schema.json';
        }
        $this->git($repository, ['add', '--', ...$tracked]);
        $this->git($repository, ['commit', '-m', 'fixture']);
        $commit = $this->git($repository, ['rev-parse', 'HEAD']);
        $this->mutateCompletion($repository, static function (array &$completion) use ($commit): void {
            $completion['commit_sha'] = $commit;
        });

        return $repository;
    }

    private function paths(string $repository): array
    {
        return [
            'lock' => $repository.'/docs/reports/contracts/plan-1a-contract-lock.json',
            'completionSchema' => $repository.'/docs/reports/contracts/plan-1a-completion.schema.json',
            'completionArtifact' => $repository.'/build/reports/plan-1a-completion.json',
            'authorizationSchema' => $repository.'/docs/reports/contracts/plan-1a-gate-evidence.schema.json',
            'authorizationArtifact' => $repository.'/build/reports/plan-1a-ci-authorization.json',
        ];
    }

    private function relativePaths(): array
    {
        return [
            'lock' => 'docs/reports/contracts/plan-1a-contract-lock.json',
            'completionSchema' => 'docs/reports/contracts/plan-1a-completion.schema.json',
            'completionArtifact' => 'build/reports/plan-1a-completion.json',
            'authorizationSchema' => 'docs/reports/contracts/plan-1a-gate-evidence.schema.json',
            'authorizationArtifact' => 'build/reports/plan-1a-ci-authorization.json',
        ];
    }

    private function verify(string $repository, ?array $arguments = null): object
    {
        $workingDirectory = getcwd();
        if (!is_string($workingDirectory) || !chdir($repository)) {
            self::fail('Unable to enter fixture repository');
        }
        try {
            return (new PlanOneACompletionVerifier())->assertReady(
                ...array_values($arguments ?? $this->relativePaths()),
            );
        } finally {
            chdir($workingDirectory);
        }
    }

    private function mutateCompletion(string $repository, callable $mutation): void
    {
        $path = $repository.'/build/reports/plan-1a-completion.json';
        $completion = $this->decode($path);
        $mutation($completion);
        $this->writeJson($path, $completion);
    }

    private function bindAuthorizationDigest(string $repository): void
    {
        $digest = hash_file('sha256', $repository.'/build/reports/plan-1a-ci-authorization.json');
        $this->mutateCompletion($repository, static function (array &$completion) use ($digest): void {
            $completion['ci_http_matrices']['authorization']['artifact_sha256'] = $digest;
        });
    }

    private function assertRejected(callable $operation, string $label = ''): void
    {
        try {
            $operation();
            self::fail('Expected rejection: '.$label);
        } catch (ReportContractException $exception) {
            self::assertSame('REPORT_INTERNAL_ERROR', $exception->getMessage(), $label);
        }
    }

    private function decode(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function writeJson(string $path, array $document): void
    {
        file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    private function git(string $repository, array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $repository);
        $process->mustRun();

        return trim($process->getOutput());
    }

    private function root(): string
    {
        return dirname(__DIR__, 4);
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/most-plan1b-handoff-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                chmod($item->getPathname(), 0777);
                rmdir($item->getPathname());
            } else {
                chmod($item->getPathname(), 0666);
                unlink($item->getPathname());
            }
        }
        chmod($directory, 0777);
        rmdir($directory);
    }
}
