<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Tooling;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class VerifyTaskSevenComposerTest extends TestCase
{
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }
    }

    public function test_initial_reviewed_commit_writes_exact_evidence_and_matches_official_content_hash(): void
    {
        [$repository, $baseline, $reviewed] = $this->repository();
        $output = $repository.'/build/reports/task-7-composer-evidence.json';
        $result = $this->verify($repository, $baseline, $reviewed, ['--output=build/reports/task-7-composer-evidence.json']);

        self::assertSame(0, $result->getExitCode(), $result->getErrorOutput());
        self::assertSame($this->expectedEvidence($repository, $baseline, $reviewed), $this->decode($output));
        self::assertSame($this->officialContentHash($repository.'/composer.json'), $this->decode($output)['content_hash']);
    }

    public function test_amended_reviewed_owner_is_compared_with_explicit_baseline(): void
    {
        [$repository, $baseline] = $this->repository();
        file_put_contents($repository.'/README.md', "owner\n");
        $this->git($repository, ['add', 'README.md']);
        $this->git($repository, ['commit', '--amend', '--no-edit']);
        $reviewed = trim($this->git($repository, ['rev-parse', 'HEAD'])->getOutput());

        $result = $this->verify($repository, $baseline, $reviewed, ['--check']);

        self::assertSame(0, $result->getExitCode(), $result->getErrorOutput());
    }

    public function test_wrong_root_and_fourth_added_package_are_rejected(): void
    {
        [$repository, $baseline, $reviewed] = $this->repository(function (array &$composer, array &$lock): void {
            $composer['require']['opis/json-schema'] = '^3.0';
        });
        $this->assertContractFailure($repository, $baseline, $reviewed, 'TASK_7_ROOT_CONSTRAINT_INVALID');

        [$repository, $baseline, $reviewed] = $this->repository(function (array &$composer, array &$lock): void {
            $lock['packages'][] = ['name' => 'extra/package', 'version' => '1.0.0'];
        });
        $this->assertContractFailure($repository, $baseline, $reviewed, 'TASK_7_ADDED_PACKAGES_INVALID');
    }

    public function test_existing_production_package_drift_is_rejected(): void
    {
        [$repository, $baseline, $reviewed] = $this->repository(
            static function (array &$composer, array &$lock): void {
                $lock['packages'][0]['version'] = '1.0.1';
            },
        );

        $this->assertContractFailure($repository, $baseline, $reviewed, 'TASK_7_PRODUCTION_PACKAGE_DRIFT');
    }

    public function test_existing_dev_package_and_packages_dev_drift_are_rejected(): void
    {
        [$repository, $baseline, $reviewed] = $this->repository(
            static function (array &$composer, array &$lock): void {
                $lock['packages-dev'][0]['version'] = '2.1.0';
            },
        );
        $this->assertContractFailure($repository, $baseline, $reviewed, 'TASK_7_DEV_PACKAGE_DRIFT');

        [$repository, $baseline, $reviewed] = $this->repository(
            static function (array &$composer, array &$lock): void {
                $lock['packages-dev'][] = ['name' => 'vendor/extra-tester', 'version' => '1.0.0'];
            },
        );
        $this->assertContractFailure($repository, $baseline, $reviewed, 'TASK_7_DEV_PACKAGE_DRIFT');
    }

    public function test_old_and_non_hex_content_hashes_are_rejected(): void
    {
        foreach (['834119c5373eedce5c218b3b8909d867', 'not-a-hash'] as $contentHash) {
            [$repository, $baseline, $reviewed] = $this->repository(
                static function (array &$composer, array &$lock) use ($contentHash): void {
                    $lock['content-hash'] = $contentHash;
                },
            );
            $this->assertContractFailure($repository, $baseline, $reviewed, 'TASK_7_CONTENT_HASH_INVALID');
        }
    }

    public function test_closed_top_level_lock_drift_matrix_is_rejected(): void
    {
        $mutations = [
            static function (array &$lock): void {
                $lock['_readme'] = ['changed'];
            },
            static function (array &$lock): void {
                $lock['platform-overrides']['php'] = '8.3.0';
            },
            static function (array &$lock): void {
                unset($lock['platform-overrides']);
            },
            static function (array &$lock): void {
                $lock['unexpected'] = true;
            },
        ];

        foreach ($mutations as $mutation) {
            [$repository, $baseline, $reviewed] = $this->repository(
                static function (array &$composer, array &$lock) use ($mutation): void {
                    $mutation($lock);
                },
            );
            $this->assertContractFailure($repository, $baseline, $reviewed, 'TASK_7_LOCK_CLOSED_TRANSFORM_DRIFT');
        }
    }

    public function test_staged_dependency_path_is_rejected(): void
    {
        [$repository, $baseline, $reviewed] = $this->repository();
        file_put_contents($repository.'/package.json', "{}\n");
        $this->git($repository, ['add', 'package.json']);

        $this->assertContractFailure($repository, $baseline, $reviewed, 'TASK_7_STAGED_DEPENDENCY_PATH');
    }

    public function test_sibling_reviewed_commit_is_rejected(): void
    {
        [$repository, $baseline, $owner] = $this->repository();
        $reviewed = trim($this->git($repository, ['commit-tree', $owner.'^{tree}', '-m', 'sibling'])->getOutput());

        $this->assertGitFailure($repository, $baseline, $reviewed, 'TASK_7_BASE_NOT_ANCESTOR');
    }

    public function test_invalid_cli_and_output_paths_preserve_caller_files_and_return_exit_two(): void
    {
        [$repository, $baseline, $reviewed] = $this->repository();
        $output = $repository.'/build/reports/task-7-composer-evidence.json';
        file_put_contents($output, 'bounded-stale');
        $result = $this->verify($repository, $baseline, $reviewed, ['--check', '--output=build/reports/task-7-composer-evidence.json']);

        self::assertSame(2, $result->getExitCode());
        self::assertStringContainsString('TASK_7_CLI_INVALID', $result->getErrorOutput());
        self::assertSame('bounded-stale', file_get_contents($output));

        $outside = dirname($repository).'/task-7-foreign-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($outside, 'outside-known-bytes');
        $invalidPaths = [
            'foreign.json' => $repository.'/foreign.json',
            $outside => $outside,
            '../'.basename($outside) => $outside,
        ];
        file_put_contents($repository.'/foreign.json', 'inside-known-bytes');

        foreach ($invalidPaths as $argument => $callerFile) {
            $expected = file_get_contents($callerFile);
            $result = $this->verify($repository, $baseline, $reviewed, ['--output='.$argument]);
            self::assertSame(2, $result->getExitCode());
            self::assertStringContainsString('TASK_7_OUTPUT_PATH_INVALID', $result->getErrorOutput());
            self::assertSame($expected, file_get_contents($callerFile));
        }
        unlink($outside);
    }

    private function repository(?callable $mutateReviewed = null): array
    {
        $repository = sys_get_temp_dir().'/most-task-7-'.bin2hex(random_bytes(8));
        mkdir($repository, 0777, true);
        mkdir($repository.'/build/reports', 0777, true);
        $this->temporaryDirectories[] = $repository;
        $fixtures = $this->root().'/tests/Fixtures/Reporting/Composer/Task7';
        copy($fixtures.'/baseline-composer.json', $repository.'/composer.json');
        copy($fixtures.'/baseline-composer.lock', $repository.'/composer.lock');
        $this->git($repository, ['init']);
        $this->git($repository, ['config', 'user.email', 'reports-test@most.local']);
        $this->git($repository, ['config', 'user.name', 'MOST Reports Test']);
        $this->git($repository, ['add', 'composer.json', 'composer.lock']);
        $this->git($repository, ['commit', '-m', 'baseline']);
        $baseline = trim($this->git($repository, ['rev-parse', 'HEAD'])->getOutput());

        $composer = json_decode(file_get_contents($fixtures.'/reviewed-composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $lock = json_decode(file_get_contents($fixtures.'/reviewed-composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        if ($mutateReviewed !== null) {
            $mutateReviewed($composer, $lock);
        }
        file_put_contents($repository.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        file_put_contents($repository.'/composer.lock', json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        $this->git($repository, ['add', 'composer.json', 'composer.lock']);
        $this->git($repository, ['commit', '-m', 'reviewed']);
        $reviewed = trim($this->git($repository, ['rev-parse', 'HEAD'])->getOutput());

        return [$repository, $baseline, $reviewed];
    }

    private function verify(string $repository, string $baseline, string $reviewed, array $mode): Process
    {
        $arguments = [
            PHP_BINARY,
            $this->root().'/scripts/reporting/verify-task-7-composer.php',
            '--baseline-commit='.$baseline,
            '--reviewed-commit='.$reviewed,
            '--expected-composer-json-sha256='.hash_file('sha256', $this->root().'/tests/Fixtures/Reporting/Composer/Task7/baseline-composer.json'),
            '--expected-composer-lock-sha256='.hash_file('sha256', $this->root().'/tests/Fixtures/Reporting/Composer/Task7/baseline-composer.lock'),
            ...$mode,
        ];
        $process = new Process($arguments, $repository);
        $process->run();

        return $process;
    }

    private function expectedEvidence(string $repository, string $baseline, string $reviewed): array
    {
        $expected = file_get_contents($this->root().'/tests/Fixtures/Reporting/Composer/Task7/expected-evidence.json');
        $replacements = [
            '{{BASELINE_COMMIT}}' => $baseline,
            '{{REVIEWED_COMMIT}}' => $reviewed,
            '{{COMPOSER_JSON_BEFORE_SHA256}}' => hash_file('sha256', $this->root().'/tests/Fixtures/Reporting/Composer/Task7/baseline-composer.json'),
            '{{COMPOSER_LOCK_BEFORE_SHA256}}' => hash_file('sha256', $this->root().'/tests/Fixtures/Reporting/Composer/Task7/baseline-composer.lock'),
            '{{COMPOSER_JSON_AFTER_SHA256}}' => hash_file('sha256', $repository.'/composer.json'),
            '{{COMPOSER_LOCK_AFTER_SHA256}}' => hash_file('sha256', $repository.'/composer.lock'),
            '{{CONTENT_HASH}}' => $this->decode($repository.'/composer.lock')['content-hash'],
        ];

        return json_decode(strtr($expected, $replacements), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertContractFailure(string $repository, string $baseline, string $reviewed, string $code): void
    {
        $output = $repository.'/build/reports/task-7-composer-evidence.json';
        $result = $this->verify($repository, $baseline, $reviewed, ['--output=build/reports/task-7-composer-evidence.json']);
        self::assertSame(4, $result->getExitCode(), $result->getErrorOutput());
        self::assertStringContainsString($code, $result->getErrorOutput());
        self::assertFileDoesNotExist($output);
    }

    private function assertGitFailure(string $repository, string $baseline, string $reviewed, string $code): void
    {
        $result = $this->verify($repository, $baseline, $reviewed, ['--check']);
        self::assertSame(3, $result->getExitCode(), $result->getErrorOutput());
        self::assertStringContainsString($code, $result->getErrorOutput());
    }

    private function git(string $repository, array $arguments): Process
    {
        $process = new Process(['git', ...$arguments], $repository);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return $process;
    }

    private function decode(string $path): array
    {
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function root(): string
    {
        return dirname(__DIR__, 4);
    }

    private function officialContentHash(string $composerJson): string
    {
        $phar = 'C:/Users/kamilgaraev/AppData/Local/CodexToolchains/most-reports/composer-2.10.2/composer.phar';
        $code = <<<'PHP'
$phar = $argv[1];
if (hash_file('sha256', $phar) !== '5ee7125f8a30a34d246cefdc0bc85b8a783b28f2aec968994118512350d28027') {
    exit(9);
}
require 'phar://'.$phar.'/vendor/autoload.php';
echo Composer\Package\Locker::getContentHash(file_get_contents($argv[2]));
PHP;
        $process = new Process([PHP_BINARY, '-r', $code, $phar, $composerJson]);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return trim($process->getOutput());
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            chmod($item->getPathname(), 0777);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        chmod($directory, 0777);
        rmdir($directory);
    }
}
