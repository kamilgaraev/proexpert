<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Tooling;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BuildReportQualityEvidenceTest extends TestCase
{
    public function test_release_phase_fails_closed_for_a_malformed_release_gate_bundle(): void
    {
        $repositoryRoot = dirname(__DIR__, 4);
        $temporaryRoot = sys_get_temp_dir().'/report-quality-release-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($temporaryRoot, 0777, true));

        try {
            $releaseSha = $this->gitOutput($repositoryRoot, ['rev-parse', 'HEAD']);
            $catalogBytes = $this->gitBlob($repositoryRoot, $releaseSha, 'docs/reports/contracts/report-platform-gates.v1.json');
            $this->prepareStandaloneScript($repositoryRoot, $temporaryRoot, $catalogBytes);
            self::assertNotFalse(file_put_contents(
                $temporaryRoot.'/docs/reports/contracts/report-release-gate-bundle.schema.json',
                (string) file_get_contents($repositoryRoot.'/docs/reports/contracts/report-release-gate-bundle.schema.json'),
            ));
            self::assertTrue(mkdir($temporaryRoot.'/build/reports', 0777, true));
            self::assertNotFalse(file_put_contents(
                $temporaryRoot.'/build/reports/report-release-gate-bundle.json',
                CanonicalJson::encode(['artifact_id' => 'arbitrary_bytes'])."\n",
            ));

            $process = new Process([
                PHP_BINARY,
                $temporaryRoot.'/scripts/reporting/build-report-quality-evidence.php',
                '--phase=release',
                '--manifest=ignored',
                '--ledger=ignored',
                '--activation-inputs=ignored',
                '--activation=ignored',
                '--activation-commit='.str_repeat('b', 40),
                '--admin-evidence-commit='.str_repeat('c', 40),
                '--gates=build/reports/report-release-gate-bundle.json',
                '--plan-1a=ignored',
                '--plan-1b=ignored',
                '--plan-1c=ignored',
                '--release-sha='.$releaseSha,
                '--generated-at=2026-07-26T00:00:00Z',
                '--output='.$temporaryRoot.'/report-quality-evidence.json',
            ], $temporaryRoot);
            $process->run();

            self::assertSame(2, $process->getExitCode());
            self::assertSame('quality-gate:invalid'.PHP_EOL, $process->getErrorOutput());
            self::assertFileDoesNotExist($temporaryRoot.'/report-quality-evidence.json');
        } finally {
            $this->removeDirectory($temporaryRoot);
        }
    }

    public function test_script_rejects_a_forged_passed_gate_artifact_hash_from_a_commit_bound_catalog(): void
    {
        $repositoryRoot = dirname(__DIR__, 4);
        $temporaryRoot = sys_get_temp_dir().'/report-quality-evidence-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($temporaryRoot, 0777, true));

        try {
            $releaseSha = $this->gitOutput($repositoryRoot, ['rev-parse', 'HEAD']);
            $catalogBytes = $this->gitBlob($repositoryRoot, $releaseSha, 'docs/reports/contracts/report-platform-gates.v1.json');
            $this->prepareStandaloneScript($repositoryRoot, $temporaryRoot, $catalogBytes);
            $this->writeGatesFixture($temporaryRoot, $releaseSha, $catalogBytes, true);

            $process = new Process([
                PHP_BINARY,
                $temporaryRoot.'/scripts/reporting/build-report-quality-evidence.php',
                '--phase=platform',
                '--manifest=ignored',
                '--official=ignored',
                '--gates=tests/Fixtures/Reporting/Quality/platform-gates.valid.json',
                '--plan-1a=ignored',
                '--plan-1b=ignored',
                '--release-sha='.$releaseSha,
                '--generated-at=2026-07-26T00:00:00Z',
                '--output='.$temporaryRoot.'/report-quality-evidence.json',
            ], $temporaryRoot);
            $process->run();

            self::assertSame(2, $process->getExitCode());
            self::assertSame('quality-gate:invalid'.PHP_EOL, $process->getErrorOutput());
            self::assertFileDoesNotExist($temporaryRoot.'/report-quality-evidence.json');
        } finally {
            $this->removeDirectory($temporaryRoot);
        }
    }

    private function prepareStandaloneScript(string $repositoryRoot, string $temporaryRoot, string $catalogBytes): void
    {
        foreach ([
            'scripts/reporting',
            'vendor',
            'docs/reports/contracts',
            'tests/Fixtures/Reporting/Quality',
        ] as $directory) {
            self::assertTrue(mkdir($temporaryRoot.'/'.$directory, 0777, true));
        }

        self::assertNotFalse(file_put_contents(
            $temporaryRoot.'/scripts/reporting/build-report-quality-evidence.php',
            (string) file_get_contents($repositoryRoot.'/scripts/reporting/build-report-quality-evidence.php'),
        ));
        self::assertNotFalse(file_put_contents(
            $temporaryRoot.'/vendor/autoload.php',
            "<?php\nrequire ".var_export($repositoryRoot.'/vendor/autoload.php', true).";\n",
        ));
        self::assertNotFalse(file_put_contents(
            $temporaryRoot.'/.git',
            'gitdir: '.$this->gitOutput($repositoryRoot, ['rev-parse', '--absolute-git-dir'])."\n",
        ));
        self::assertNotFalse(file_put_contents(
            $temporaryRoot.'/docs/reports/contracts/report-platform-gates.v1.json',
            $catalogBytes."\n",
        ));
    }

    private function writeGatesFixture(string $repositoryRoot, string $releaseSha, string $catalogBytes, bool $forgeArtifact): void
    {
        $catalog = json_decode($catalogBytes, true, 512, JSON_THROW_ON_ERROR);
        $gates = [];
        foreach ($catalog['gates'] as $index => $definition) {
            $sources = [];
            foreach ($definition['source_paths'] as $path) {
                $sources[] = [
                    'path' => $path,
                    'sha256' => hash('sha256', $this->gitBlob($repositoryRoot, $releaseSha, $path)),
                ];
            }
            $artifactHash = $definition['platform_status'] === 'passed'
                ? hash('sha256', CanonicalJson::encode($sources))
                : null;
            if ($forgeArtifact && $index === 0) {
                $artifactHash = str_repeat('0', 64);
            }
            $gates[] = [
                'gate' => $definition['id'],
                'owner_plan' => $definition['release_owner'],
                'phase' => 'platform',
                'status' => $definition['platform_status'],
                'command' => $definition['command'],
                'count' => $definition['minimum_count'],
                'schema_sha256' => $definition['schema_sha256'],
                'release_sha' => $releaseSha,
                'commit_sha' => $releaseSha,
                'executed_at' => '2026-07-26T00:00:00Z',
                'artifact_sha256' => $artifactHash,
                'source_artifacts' => $sources,
            ];
        }

        $document = [
            'artifact_id' => 'report_platform_gate_inputs',
            'schema_version' => '1.0.0',
            'status' => 'platform_gate_inputs_passed',
            'catalog' => [
                'path' => 'docs/reports/contracts/report-platform-gates.v1.json',
                'sha256' => hash('sha256', $catalogBytes),
            ],
            'release_sha' => $releaseSha,
            'generated_at' => '2026-07-26T00:00:00Z',
            'gates' => $gates,
        ];
        self::assertNotFalse(file_put_contents(
            $repositoryRoot.'/tests/Fixtures/Reporting/Quality/platform-gates.valid.json',
            CanonicalJson::encode($document)."\n",
        ));
    }

    private function gitBlob(string $repositoryRoot, string $releaseSha, string $path): string
    {
        $process = new Process(['git', 'show', $releaseSha.':'.$path], $repositoryRoot);
        $process->mustRun();

        return $process->getOutput();
    }

    /** @param list<string> $arguments */
    private function gitOutput(string $repositoryRoot, array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $repositoryRoot);
        $process->mustRun();

        return trim($process->getOutput());
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
