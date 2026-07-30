<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Quality;

use App\BusinessModules\Core\Reporting\Application\Quality\ReportReleaseGateBundleBuilder;
use App\BusinessModules\Core\Reporting\Application\Quality\ReportPlatformGateCatalog;
use App\BusinessModules\Core\Reporting\Domain\DTO\JointQG14Evidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQualityGateEvidence;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityEvidencePhase;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityEvidenceStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ReportReleaseGateBundleBuilderTest extends TestCase
{
    private string $sourceRoot;

    /** @var list<string> */
    private array $createdSourceArtifacts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceRoot = dirname(__DIR__, 4);
        foreach ($this->sourceDefinitions() as $index => [$artifactId, $kind, $path]) {
            $artifactPath = $this->sourceRoot.'/'.$path;
            if (! file_exists($artifactPath)) {
                $directory = dirname($artifactPath);
                if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
                    throw new \RuntimeException('Unable to create release artifact fixture directory.');
                }
                file_put_contents(
                    $artifactPath,
                    $this->structuredSourceBytes($index, $artifactId, $kind, $path),
                );
                $this->createdSourceArtifacts[] = $artifactPath;
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->createdSourceArtifacts as $artifactPath) {
            unlink($artifactPath);
            for ($directory = dirname($artifactPath); $directory !== $this->sourceRoot && @rmdir($directory); $directory = dirname($directory)) {
            }
        }

        parent::tearDown();
    }

    public function test_builds_a_closed_fourteen_gate_bundle_with_the_9_4_1_ownership_map(): void
    {
        $bundle = $this->builder()->build(
            $this->gates(),
            $this->qg14Evidence(),
            str_repeat('a', 40),
            $this->sources(),
            str_repeat('b', 40),
            str_repeat('c', 40),
            new DateTimeImmutable('2026-07-26T00:00:00Z'),
        );

        self::assertSame('release_gates_passed', $bundle->status);
        self::assertSame(str_repeat('b', 40), $bundle->activationCommitSha);
        self::assertSame(str_repeat('c', 40), $bundle->adminEvidenceCommitSha);
        self::assertSame(
            hash('sha256', CanonicalJson::encode($bundle->sources)),
            $bundle->sectionHashes['source_artifacts'],
        );
        self::assertSame(['backend' => 9, 'admin' => 4, 'joint' => 1], $bundle->ownershipCounts);
        self::assertSame('both', $bundle->gates[13]->ownerPlan);
    }

    public function test_rejects_any_attempt_to_move_qg06_into_joint_ownership(): void
    {
        $gates = $this->gates();
        $gates[5] = $this->gate('QG-06', 'both', 46);

        $this->expectExceptionObject(new \App\BusinessModules\Core\Reporting\Application\Quality\ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE));

        $this->build($gates);
    }

    public function test_rejects_an_arbitrary_command_or_schema_hash_for_a_catalog_gate(): void
    {
        $gates = $this->gates();
        $gates[0] = new ReportQualityGateEvidence('QG-01', 'backend', ReportQualityEvidencePhase::RELEASE, ReportQualityEvidenceStatus::PASSED, 'arbitrary-command', 28, new Sha256Hash(str_repeat('b', 64)), str_repeat('a', 40), str_repeat('c', 40), new DateTimeImmutable('2026-07-26T00:00:00Z'), new Sha256Hash(str_repeat('d', 64)));

        $this->expectExceptionObject(new \App\BusinessModules\Core\Reporting\Application\Quality\ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE));

        $this->build($gates);
    }

    public function test_rejects_a_bundle_without_exactly_thirteen_distinct_source_artifacts(): void
    {
        $sources = $this->sources();
        array_pop($sources);

        $this->expectExceptionObject(new \App\BusinessModules\Core\Reporting\Application\Quality\ReportQualityGateException(ReportQualityGateFailureCode::CATALOG_COUNT_MISMATCH));

        $this->build($this->gates(), $sources);
    }

    public function test_rejects_source_artifact_hash_that_does_not_match_its_file_bytes(): void
    {
        $sources = $this->sources();
        $sources[0]['bytes_sha256'] = str_repeat('f', 64);

        $this->expectExceptionObject(new \App\BusinessModules\Core\Reporting\Application\Quality\ReportQualityGateException(ReportQualityGateFailureCode::CATALOG_COUNT_MISMATCH));

        $this->build($this->gates(), $sources);
    }

    public function test_rejects_structurally_empty_source_bytes_even_when_the_hash_matches(): void
    {
        $path = $this->sourceRoot.'/build/reports/plan-2-wave-1-evidence.json';
        file_put_contents($path, "plan-2-wave-1-candidate-conformance\n");
        $sources = $this->sources();

        $this->expectExceptionObject(new \App\BusinessModules\Core\Reporting\Application\Quality\ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE));

        $this->build($this->gates(), $sources);
    }

    public function test_release_bundle_cli_requires_primary_artifacts_without_requiring_a_complete_input_bundle(): void
    {
        $root = dirname(__DIR__, 4);
        $command = [
            PHP_BINARY,
            $root.'/scripts/reporting/build-report-release-gate-bundle.php',
            '--release-sha='.str_repeat('a', 40),
            '--activation-commit='.str_repeat('b', 40),
            '--admin-evidence-commit='.str_repeat('c', 40),
            '--generated-at=2026-07-26T00:00:00Z',
            '--admin-root=C:/admin',
            '--backend-root=C:/backend',
            '--output='.$root.'/build/reports/report-release-gate-bundle.json',
        ];
        foreach (array_column($this->sourceDefinitions(), 2) as $path) {
            $command[] = '--'.str_replace('_', '-', pathinfo($path, PATHINFO_FILENAME)).'='.$root.'/'.$path;
        }
        $command[11] = '--plan-1c-platform-completion='.$root.'/build/reports/plan-1c-platform-completion.json';
        $command[12] = '--plan-2-wave-1-evidence='.$root.'/build/reports/plan-2-wave-1-evidence.json';
        $command[13] = '--waves-2-3-candidate-contribution='.$root.'/build/reports/waves-2-3-candidate-contribution.json';
        $command[14] = '--plan-3-waves-2-3-evidence='.$root.'/build/reports/plan-3-waves-2-3-evidence.json';
        $command[15] = '--activation-inputs='.$root.'/build/reports/report-catalog-activation-inputs.json';
        $command[16] = '--activation='.$root.'/build/reports/report-catalog-activation.json';
        $command[17] = '--admin-evidence='.$root.'/build/reports/intake/plan-4-admin-evidence.json';
        $command[18] = '--admin-evidence-schema='.$root.'/build/reports/intake/contracts/report-admin-evidence.schema.json';
        $command[19] = '--admin-transfer='.$root.'/build/reports/intake/plan-4-admin-evidence.transfer.json';
        $command[20] = '--active-manifest='.$root.'/app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml';
        $command[21] = '--active-ledger='.$root.'/app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json';

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(2, proc_close($process));
        self::assertSame("quality-gate:invalid\n", $stderr);
    }

    /** @return list<ReportQualityGateEvidence> */
    private function gates(): array
    {
        return $this->builder()->loadGateEvidence(
            $this->sources(),
            str_repeat('a', 40),
            str_repeat('b', 40),
            str_repeat('c', 40),
        );
    }

    private function gate(string $id, string $owner, int $count): ReportQualityGateEvidence
    {
        $definition = (new ReportPlatformGateCatalog(dirname(__DIR__, 4).'/docs/reports/contracts/report-platform-gates.v1.json'))->records()[(int) substr($id, -2) - 1];

        return new ReportQualityGateEvidence($id, $owner, ReportQualityEvidencePhase::RELEASE, ReportQualityEvidenceStatus::PASSED, $definition['command'], $count, new Sha256Hash($definition['schema_sha256']), str_repeat('a', 40), str_repeat('c', 40), new DateTimeImmutable('2026-07-26T00:00:00Z'), new Sha256Hash(str_repeat('d', 64)));
    }

    private function qg14Evidence(): JointQG14Evidence
    {
        return new JointQG14Evidence(0, 0, 0, new Sha256Hash(str_repeat('1', 64)), new Sha256Hash(str_repeat('2', 64)), new Sha256Hash(str_repeat('3', 64)), ['node', 'scripts/verify-reporting-cutover.mjs', '--admin-root=C:/admin', '--backend-root=C:/backend'], 'qg14_forbidden_symbols');
    }

    private function builder(): ReportReleaseGateBundleBuilder
    {
        return new ReportReleaseGateBundleBuilder();
    }

    private function build(array $gates, ?array $sources = null): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportReleaseGateBundle
    {
        return $this->builder()->build(
            $gates,
            $this->qg14Evidence(),
            str_repeat('a', 40),
            $sources ?? $this->sources(),
            str_repeat('b', 40),
            str_repeat('c', 40),
            new DateTimeImmutable('2026-07-26T00:00:00Z'),
        );
    }

    private function structuredSourceBytes(int $index, string $artifactId, string $kind, string $path): string
    {
        if ($path === 'build/reports/intake/contracts/report-admin-evidence.schema.json') {
            return CanonicalJson::encode([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'additionalProperties' => false,
            ])."\n";
        }

        if ($kind === 'tracked_file') {
            throw new \LogicException('Tracked release source fixture must already exist.');
        }

        $gateRanges = [
            2 => [1],
            3 => [2, 3, 4, 5],
            4 => [6, 7],
            5 => [8, 9],
            8 => [10, 11, 12, 13, 14],
        ];
        $gateEvidence = [];
        foreach ($gateRanges[$index] ?? [] as $gateNumber) {
            $definition = (new ReportPlatformGateCatalog(
                $this->sourceRoot.'/docs/reports/contracts/report-platform-gates.v1.json',
            ))->records()[$gateNumber - 1];
            $evidence = [
                'source_artifact_id' => $artifactId,
                'gate' => $definition['id'],
                'result' => 'passed',
                'observed_count' => $definition['minimum_count'],
            ];
            $gateEvidence[] = [
                'gate' => $definition['id'],
                'owner_plan' => $definition['release_owner'],
                'status' => 'passed',
                'command' => $definition['command'],
                'count' => $definition['minimum_count'],
                'schema_sha256' => $definition['schema_sha256'],
                'commit_sha' => str_repeat('c', 40),
                'executed_at' => '2026-07-26T00:00:00Z',
                'evidence' => $evidence,
                'artifact_sha256' => hash('sha256', CanonicalJson::encode($evidence)),
            ];
        }
        $sections = ['gate_evidence' => $gateEvidence];
        $document = [
            'artifact_id' => $artifactId,
            'schema_version' => '1.0.0',
            'status' => $kind === 'transfer' ? 'artifact_transferred' : 'passed',
            'producer_commit_sha' => str_repeat('c', 40),
            'generated_at' => '2026-07-26T00:00:00Z',
            'gate_evidence' => $gateEvidence,
            'section_hashes' => [
                'gate_evidence' => hash('sha256', CanonicalJson::encode($sections['gate_evidence'])),
            ],
        ];
        if ($kind !== 'ancestor_evidence') {
            $document['release_sha'] = str_repeat('a', 40);
        }
        if ($kind === 'transfer') {
            $document['activation_commit_sha'] = str_repeat('b', 40);
            $document['admin_evidence_commit_sha'] = str_repeat('c', 40);
        }

        return CanonicalJson::encode($document)."\n";
    }

    /** @return list<array{artifact_id: string, kind: string, path: string, bytes_sha256: string}> */
    private function sources(): array
    {
        return array_map(fn (array $source): array => [
            'artifact_id' => $source[0],
            'kind' => $source[1],
            'path' => $source[2],
            'bytes_sha256' => hash_file('sha256', $this->sourceRoot.'/'.$source[2]),
        ], $this->sourceDefinitions());
    }

    /** @return list<array{string, string, string}> */
    private function sourceDefinitions(): array
    {
        return [
            ['plan-1a-completion', 'ancestor_evidence', 'build/reports/plan-1a-completion.json'],
            ['plan-1b-completion', 'ancestor_evidence', 'build/reports/plan-1b-completion.json'],
            ['plan-1c-platform-completion', 'ancestor_evidence', 'build/reports/plan-1c-platform-completion.json'],
            ['plan-2-wave-1-candidate-conformance', 'release_evidence', 'build/reports/plan-2-wave-1-evidence.json'],
            ['plan3_waves23_candidate_contribution', 'release_evidence', 'build/reports/waves-2-3-candidate-contribution.json'],
            ['plan3_waves23_evidence', 'release_evidence', 'build/reports/plan-3-waves-2-3-evidence.json'],
            ['report_catalog_activation_inputs', 'release_evidence', 'build/reports/report-catalog-activation-inputs.json'],
            ['report_catalog_activation', 'release_evidence', 'build/reports/report-catalog-activation.json'],
            ['plan4_admin_qg10_qg14_evidence', 'release_evidence', 'build/reports/intake/plan-4-admin-evidence.json'],
            ['plan4_admin_evidence_schema', 'tracked_file', 'build/reports/intake/contracts/report-admin-evidence.schema.json'],
            ['plan4_admin_evidence_transfer', 'transfer', 'build/reports/intake/plan-4-admin-evidence.transfer.json'],
            ['report_management_catalog_active', 'tracked_file', 'app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml'],
            ['report_publication_ledger_active', 'tracked_file', 'app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json'],
        ];
    }
}
