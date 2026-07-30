<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Evidence;

use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneCPlatformEvidenceBuilder;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneCPrerequisiteEvidenceValidator;
use App\BusinessModules\Core\Reporting\Domain\DTO\TrackedPlanDocument;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PlanOneCPlatformEvidenceBuilderTest extends TestCase
{
    public function test_build_uses_repository_commit_blobs_instead_of_working_tree_sources(): void
    {
        $sourceRoot = dirname(__DIR__, 4);
        $repositoryRoot = sys_get_temp_dir().'/plan-one-c-evidence-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($repositoryRoot, 0777, true));

        try {
            $targetCommit = $this->currentCommit($sourceRoot);
            $this->createLinkedWorkingTreeSnapshot($sourceRoot, $repositoryRoot, $targetCommit);

            $targetSourceHashes = $this->sourceHashes($repositoryRoot, $targetCommit);
            $translationPath = $repositoryRoot.'/'.$this->sourceHashPaths()['translation'];
            self::assertNotFalse(file_put_contents($translationPath, "\nreturn ['snapshot' => 'changed'];\n", FILE_APPEND));

            $workingTreeSourceHashes = $this->workingTreeSourceHashes($repositoryRoot);
            self::assertNotSame(
                $targetSourceHashes['translation'],
                $workingTreeSourceHashes['translation'],
            );

            $document = $this->builder($repositoryRoot, $targetCommit)->build(
                $this->prerequisiteBundle($sourceRoot),
                $this->planOneB($targetCommit),
                $this->planOneC($targetCommit),
                $targetCommit,
                new DateTimeImmutable('2026-07-26T00:00:00Z'),
                [
                    'platform_quality' => $this->platformQuality($repositoryRoot, $targetCommit),
                    'ci_artifacts' => $this->ciArtifacts($repositoryRoot, $targetCommit),
                    'published_count' => 28,
                    'binding_count' => 28,
                    'unresolved_risks' => [],
                ],
            );

            self::assertSame(
                $this->gitBlobHash(
                    $repositoryRoot,
                    $targetCommit,
                    'docs/reports/contracts/plan-1c-contract-lock.json',
                ),
                $document['plan_1c_lock_sha256'],
            );
            self::assertSame(
                $this->gitBlobHash(
                    $repositoryRoot,
                    $targetCommit,
                    'docs/reports/contracts/report-platform-gates.v1.json',
                ),
                $document['platform_quality_catalog_sha256'],
            );
            self::assertSame($targetSourceHashes, $document['source_hashes']);
            self::assertNotSame($workingTreeSourceHashes, $document['source_hashes']);
        } finally {
            $this->removeDirectory($repositoryRoot);
        }
    }

    public function test_rejects_forged_structured_passed_ci_artifact(): void
    {
        $root = dirname(__DIR__, 4);
        $commit = $this->currentCommit($root);
        $ciArtifacts = $this->ciArtifacts($root, $commit);
        $ciArtifacts['workspace']['forged'] = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('plan_one_c_platform_evidence_invalid');

        $this->builder($root, $commit)->build(
            $this->prerequisiteBundle($root),
            $this->planOneB($commit),
            $this->planOneC($commit),
            $commit,
            new DateTimeImmutable('2026-07-26T00:00:00Z'),
            [
                'platform_quality' => $this->platformQuality($root, $commit),
                'ci_artifacts' => $ciArtifacts,
                'published_count' => 28,
                'binding_count' => 28,
                'unresolved_risks' => [],
            ],
        );
    }

    public function test_rejects_legacy_count_only_platform_evidence_payload(): void
    {
        $root = dirname(__DIR__, 4);
        $bundle = (new PlanOneCPrerequisiteEvidenceValidator($root))
            ->validateBundle($root.'/tests/Fixtures/Reporting/Prerequisites/artifact-bundle.valid.json');
        $commit = str_repeat('1', 40);
        $planOneB = new TrackedPlanDocument(
            'docs/superpowers/plans/2026-07-26-reports-plan-1b-execution-exports.md',
            $commit,
            new Sha256Hash(PlanOneCPlatformEvidenceBuilder::PLAN_ONE_B_SHA256),
            '',
        );
        $planOneC = new TrackedPlanDocument(
            'docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md',
            $commit,
            new Sha256Hash(str_repeat('a', 64)),
            '',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('plan_one_c_platform_evidence_invalid');

        (new PlanOneCPlatformEvidenceBuilder($root))->build(
            $bundle,
            $planOneB,
            $planOneC,
            $commit,
            new DateTimeImmutable('2026-07-26T00:00:00Z'),
            [
                'published_count' => 0,
                'binding_count' => 0,
                'unresolved_risks' => [],
            ],
        );
    }

    public function test_rejects_forged_passed_platform_gate_artifact_hash(): void
    {
        $root = dirname(__DIR__, 4);
        $commit = $this->currentCommit($root);
        $platformQuality = $this->platformQuality($root, $commit);
        $platformQuality['gates'][0]['artifact_sha256'] = str_repeat('0', 64);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('plan_one_c_platform_evidence_invalid');

        $this->builder($root, $commit)->build(
            $this->prerequisiteBundle($root),
            $this->planOneB($commit),
            $this->planOneC($commit),
            $commit,
            new DateTimeImmutable('2026-07-26T00:00:00Z'),
            [
                'platform_quality' => $platformQuality,
                'ci_artifacts' => $this->ciArtifacts($root, $commit),
                'published_count' => 28,
                'binding_count' => 28,
                'unresolved_risks' => [],
            ],
        );
    }

    public function test_rejects_ci_source_hashes_that_do_not_match_repository_commit(): void
    {
        $root = dirname(__DIR__, 4);
        $commit = $this->currentCommit($root);
        $ciArtifacts = $this->ciArtifacts($root, $commit);
        $ciArtifacts['workspace']['source_hashes']['manifest'] = str_repeat('0', 64);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('plan_one_c_platform_evidence_invalid');

        $this->builder($root, $commit)->build(
            $this->prerequisiteBundle($root),
            $this->planOneB($commit),
            $this->planOneC($commit),
            $commit,
            new DateTimeImmutable('2026-07-26T00:00:00Z'),
            [
                'platform_quality' => $this->platformQuality($root, $commit),
                'ci_artifacts' => $ciArtifacts,
                'published_count' => 28,
                'binding_count' => 28,
                'unresolved_risks' => [],
            ],
        );
    }

    private function builder(string $root, string $commit): PlanOneCPlatformEvidenceBuilder
    {
        return new PlanOneCPlatformEvidenceBuilder($root);
    }

    private function currentCommit(string $root): string
    {
        $output = [];
        $exitCode = 0;
        exec('git -C '.escapeshellarg($root).' rev-parse HEAD', $output, $exitCode);
        self::assertSame(0, $exitCode);
        self::assertCount(1, $output);

        return $output[0];
    }

    private function prerequisiteBundle(string $root): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportPrerequisiteEvidenceBundle
    {
        return (new PlanOneCPrerequisiteEvidenceValidator($root))
            ->validateBundle($root.'/tests/Fixtures/Reporting/Prerequisites/artifact-bundle.valid.json');
    }

    private function planOneB(string $commit): TrackedPlanDocument
    {
        return new TrackedPlanDocument(
            'docs/superpowers/plans/2026-07-26-reports-plan-1b-execution-exports.md',
            $commit,
            new Sha256Hash(PlanOneCPlatformEvidenceBuilder::PLAN_ONE_B_SHA256),
            '',
        );
    }

    private function planOneC(string $commit): TrackedPlanDocument
    {
        return new TrackedPlanDocument(
            'docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md',
            $commit,
            new Sha256Hash(str_repeat('a', 64)),
            '',
        );
    }

    /** @return array<string, mixed> */
    private function platformQuality(string $root, string $commit): array
    {
        $catalog = json_decode(
            $this->gitBlob($root, $commit, 'docs/reports/contracts/report-platform-gates.v1.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $gates = [];
        foreach ($catalog['gates'] as $gate) {
            $sources = [];
            foreach ($gate['source_paths'] as $path) {
                $sources[] = ['path' => $path, 'sha256' => $this->gitBlobHash($root, $commit, $path)];
            }
            $qualityGate = [
                'gate' => $gate['id'],
                'owner_plan' => $gate['release_owner'],
                'phase' => 'platform',
                'status' => $gate['platform_status'],
                'command' => $gate['command'],
                'count' => $gate['minimum_count'],
                'schema_sha256' => $gate['schema_sha256'],
                'release_sha' => $commit,
                'commit_sha' => $commit,
                'executed_at' => '2026-07-26T00:00:00Z',
                'source_artifacts' => $sources,
            ];
            if ($gate['platform_status'] === 'passed') {
                $qualityGate['artifact_sha256'] = hash('sha256', CanonicalJson::encode($sources));
            } else {
                $qualityGate['artifact_sha256'] = null;
            }
            $gates[] = $qualityGate;
        }

        return [
            'artifact_id' => 'report_quality_evidence',
            'schema_version' => '1.0.0',
            'status' => 'platform_passed',
            'catalog' => ['path' => 'docs/reports/contracts/report-platform-gates.v1.json', 'sha256' => $this->gitBlobHash($root, $commit, 'docs/reports/contracts/report-platform-gates.v1.json')],
            'release_sha' => $commit,
            'generated_at' => '2026-07-26T00:00:00Z',
            'gates' => $gates,
        ];
    }

    private function ciArtifacts(string $root, string $commit): array
    {
        $sourceHashes = $this->sourceHashes($root, $commit);
        $artifacts = [];
        foreach (['workspace', 'saved_views', 'subscriptions', 'integration', 'fake_sequence'] as $name) {
            $output = ['check_id' => 'reporting_ci_'.$name, 'status' => 'passed', 'count' => 28];
            $artifacts[$name] = [
                'artifact_id' => 'reporting_ci_'.$name,
                'schema_version' => '1.0.0',
                'status' => 'passed',
                'repository_commit' => $commit,
                'source_hashes' => $sourceHashes,
                'command_record' => [
                    'command' => 'reporting-ci-'.$name,
                    'status' => 'passed',
                    'count' => 28,
                    'duration_ms' => 0,
                    'output_sha256' => hash('sha256', CanonicalJson::encode($output)."\n"),
                ],
                'output' => $output,
                'published_count' => 28,
                'binding_count' => 28,
                'unresolved_risks' => [],
            ];
        }

        return $artifacts;
    }

    /** @return array<string, string> */
    private function sourceHashes(string $root, string $commit): array
    {
        $hashes = [];
        foreach ($this->sourceHashPaths() as $key => $path) {
            $hashes[$key] = $this->gitBlobHash($root, $commit, $path);
        }

        return $hashes;
    }

    private function gitBlobHash(string $root, string $commit, string $path): string
    {
        return hash('sha256', $this->gitBlob($root, $commit, $path));
    }

    private function gitBlob(string $root, string $commit, string $path): string
    {
        $bytes = shell_exec(
            'git -C '.escapeshellarg($root).' show '.escapeshellarg($commit.':'.$path),
        );
        self::assertIsString($bytes);

        return $bytes;
    }

    /** @return array<string, string> */
    private function sourceHashPaths(): array
    {
        return [
            'manifest' => 'app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml',
            'official_manifest' => 'app/BusinessModules/Core/Reporting/resources/official-document-catalog.v1.yaml',
            'generated_catalog' => 'docs/reports/generated/reporting-catalog.v1.json',
            'resource' => 'docs/reports/contracts/reporting-admin-resources.v1.schema.json',
            'permission' => 'docs/reports/generated/report-permissions.v1.json',
            'translation' => 'lang/ru/reports.php',
            'route' => 'app/BusinessModules/Core/Reporting/routes.php',
            'schema' => 'app/BusinessModules/Core/Reporting/resources/management-catalog.v1.schema.json',
            'candidate_validation' => 'docs/reports/contracts/report-candidate-validation.schema.json',
            'conformance_framework' => 'docs/reports/contracts/report-conformance-evidence.schema.json',
            'publication_framework' => 'docs/reports/contracts/report-publication-ledger.schema.json',
            'platform_quality_ledger' => 'docs/reports/contracts/report-quality-evidence.schema.json',
        ];
    }

    /** @return array<string, string> */
    private function workingTreeSourceHashes(string $root): array
    {
        $hashes = [];
        foreach ($this->sourceHashPaths() as $key => $path) {
            $hash = hash_file('sha256', $root.'/'.$path);
            self::assertIsString($hash);
            $hashes[$key] = $hash;
        }

        return $hashes;
    }

    private function createLinkedWorkingTreeSnapshot(string $sourceRoot, string $snapshotRoot, string $commit): void
    {
        $gitDirectory = $this->gitOutput($sourceRoot, ['rev-parse', '--absolute-git-dir']);
        self::assertNotSame('', $gitDirectory);
        self::assertNotFalse(
            file_put_contents(
                $snapshotRoot.'/.git',
                'gitdir: '.str_replace('\\', '/', $gitDirectory)."\n",
            ),
        );

        $paths = [
            'docs/reports/contracts/plan-1c-contract-lock.json',
            'docs/reports/contracts/plan-1c-platform-completion.schema.json',
            'docs/reports/contracts/report-platform-gates.v1.json',
            'docs/reports/contracts/report-quality-evidence.schema.json',
            ...array_values($this->sourceHashPaths()),
        ];
        $catalog = json_decode(
            $this->gitBlob($snapshotRoot, $commit, 'docs/reports/contracts/report-platform-gates.v1.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        foreach ($catalog['gates'] as $gate) {
            foreach ($gate['source_paths'] as $path) {
                $paths[] = $path;
            }
        }

        foreach (array_unique($paths) as $path) {
            $target = $snapshotRoot.'/'.$path;
            $directory = dirname($target);
            if (!is_dir($directory)) {
                self::assertTrue(mkdir($directory, 0777, true));
            }
            self::assertNotFalse(file_put_contents($target, $this->gitBlob($snapshotRoot, $commit, $path)));
        }
    }

    /** @param list<string> $arguments */
    private function gitOutput(string $root, array $arguments): string
    {
        $command = 'git -C '.escapeshellarg($root);
        foreach ($arguments as $argument) {
            $command .= ' '.escapeshellarg($argument);
        }
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        self::assertSame(0, $exitCode);

        return trim(implode("\n", $output));
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
