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
        $catalogPath = $root.'/docs/reports/contracts/report-platform-gates.v1.json';
        $catalog = json_decode((string) file_get_contents($catalogPath), true, 512, JSON_THROW_ON_ERROR);
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
        return [
            'manifest' => $this->gitBlobHash($root, $commit, 'app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml'),
            'official_manifest' => $this->gitBlobHash($root, $commit, 'app/BusinessModules/Core/Reporting/resources/official-document-catalog.v1.yaml'),
            'generated_catalog' => $this->gitBlobHash($root, $commit, 'docs/reports/generated/reporting-catalog.v1.json'),
            'resource' => $this->gitBlobHash($root, $commit, 'docs/reports/contracts/reporting-admin-resources.v1.schema.json'),
            'permission' => $this->gitBlobHash($root, $commit, 'docs/reports/generated/report-permissions.v1.json'),
            'translation' => $this->gitBlobHash($root, $commit, 'lang/ru/reports.php'),
            'route' => $this->gitBlobHash($root, $commit, 'app/BusinessModules/Core/Reporting/routes.php'),
            'schema' => $this->gitBlobHash($root, $commit, 'app/BusinessModules/Core/Reporting/resources/management-catalog.v1.schema.json'),
            'candidate_validation' => $this->gitBlobHash($root, $commit, 'docs/reports/contracts/report-candidate-validation.schema.json'),
            'conformance_framework' => $this->gitBlobHash($root, $commit, 'docs/reports/contracts/report-conformance-evidence.schema.json'),
            'publication_framework' => $this->gitBlobHash($root, $commit, 'docs/reports/contracts/report-publication-ledger.schema.json'),
            'platform_quality_ledger' => $this->gitBlobHash($root, $commit, 'docs/reports/contracts/report-quality-evidence.schema.json'),
        ];
    }

    private function gitBlobHash(string $root, string $commit, string $path): string
    {
        $bytes = shell_exec('git -C '.escapeshellarg($root).' show '.escapeshellarg($commit.':'.$path));
        self::assertIsString($bytes);

        return hash('sha256', $bytes);
    }
}
