<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting\Reporting;

use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactPublicationCandidateArtifactBuilder;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactReleaseCandidateLayout;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactReleaseCandidateResolver;
use App\BusinessModules\Features\Budgeting\Services\PlanFactCalculator;
use App\BusinessModules\Features\Budgeting\Services\PlanFactSourceSnapshotMaterializer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Budgeting\BudgetPlanFactCandidateFixture;

final class BudgetPlanFactPublicationCandidateArtifactBuilderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-budget-plan-fact-artifact-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->delete($this->root);
        parent::tearDown();
    }

    public function test_writes_a_byte_deterministic_canonical_artifact_and_resolves_it(): void
    {
        $first = $this->outputDirectory('first');
        $second = $this->outputDirectory('second');
        [$candidate, $conformance, $proof] = $this->documents();

        $builder = new BudgetPlanFactPublicationCandidateArtifactBuilder;
        $builder->build($first, $this->commit(), $candidate, $conformance, $proof);
        $builder->build($second, $this->commit(), $candidate, $conformance, $proof);

        self::assertSame([
            BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST,
            BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE,
            BudgetPlanFactReleaseCandidateLayout::PROOF_TEMPLATE,
            BudgetPlanFactReleaseCandidateLayout::REQUEST_FILE,
        ], array_values(array_filter(scandir($first) ?: [], static fn (string $file): bool => $file !== '.' && $file !== '..')));
        foreach (scandir($first) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                self::assertSame(file_get_contents($first.DIRECTORY_SEPARATOR.$file), file_get_contents($second.DIRECTORY_SEPARATOR.$file));
            }
        }
        self::assertSame(
            BudgetPlanFactCandidateContract::CODE,
            (new BudgetPlanFactReleaseCandidateResolver)->resolve($first, $this->commit())[BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST]['code'],
        );
    }

    public function test_existing_resolver_rejects_missing_tampered_cross_code_and_wrong_sha_artifacts(): void
    {
        $directory = $this->build();
        unlink($directory.DIRECTORY_SEPARATOR.BudgetPlanFactReleaseCandidateLayout::PROOF_TEMPLATE);
        $this->assertRejected($directory, $this->commit());

        $directory = $this->build();
        file_put_contents($directory.DIRECTORY_SEPARATOR.BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST, '{}');
        $this->assertRejected($directory, $this->commit());

        $directory = $this->build();
        $request = $this->read($directory, BudgetPlanFactReleaseCandidateLayout::REQUEST_FILE);
        $request['code'] = 'procurement_cycle';
        $this->write($directory, BudgetPlanFactReleaseCandidateLayout::REQUEST_FILE, $request);
        $this->assertRejected($directory, $this->commit());

        $directory = $this->build();
        $this->assertRejected($directory, str_repeat('b', 40));
    }

    private function build(): string
    {
        [$candidate, $conformance, $proof] = $this->documents();
        $directory = $this->outputDirectory(bin2hex(random_bytes(4)));
        (new BudgetPlanFactPublicationCandidateArtifactBuilder)->build($directory, $this->commit(), $candidate, $conformance, $proof);

        return $directory;
    }

    /** @return array{array<string,mixed>, array<string,mixed>, array<string,mixed>} */
    private function documents(): array
    {
        $contract = BudgetPlanFactCandidateFixture::contract();
        $definition = [
            'capabilities' => ['supports_subscriptions' => false],
            'code' => BudgetPlanFactCandidateContract::CODE,
            'columns' => $contract->columns(),
            'filters' => $contract->filters(),
            'formats' => $contract->formats(),
            'permissions' => ['audit' => [], 'export' => ['budgeting.plan_fact.export'], 'sensitive' => [], 'view' => ['budgeting.plan_fact.view']],
            'readiness' => ['delivery' => 'verified', 'formula' => 'ready', 'publication' => 'candidate', 'source' => 'ready'],
            'sorts' => $contract->sorts(),
            'versions' => ['contract' => '1.0.0', 'formula' => $contract->formulaVersion, 'renderer' => '1.0.0', 'source_schema' => $contract->sourceSchemaVersion],
        ];
        $close = ['plan_identity' => 'budget-1', 'organization_id' => 1, 'period_end' => '2026-01-31', 'period_start' => '2026-01-01', 'scenario_identity' => 'scenario-1'];
        $candidate = [
            'candidate_definition' => $definition, 'candidate_definition_sha256' => $this->hash($definition), 'code' => BudgetPlanFactCandidateContract::CODE,
            'formula_sha256' => BudgetPlanFactCandidateContract::FORMULA_HASH, 'formula_version' => $contract->formulaVersion, 'generated_from_commit' => $this->commit(), 'publication_status' => 'candidate',
            'source_close_id' => BudgetPlanFactCandidateFixture::closeId(), 'source_close_identity' => $close, 'source_close_identity_sha256' => $this->hash($close), 'source_schema_version' => $contract->sourceSchemaVersion, 'source_sha256' => BudgetPlanFactCandidateContract::SOURCE_HASH,
        ];
        $conformance = [
            'assertion_count' => 2, 'code' => BudgetPlanFactCandidateContract::CODE, 'commit_sha' => $this->commit(),
            'component_class_hashes' => [['class' => PlanFactCalculator::class, 'sha256' => BudgetPlanFactCandidateContract::FORMULA_HASH], ['class' => PlanFactSourceSnapshotMaterializer::class, 'sha256' => BudgetPlanFactCandidateContract::SOURCE_HASH]],
            'contract_version' => '1.0.0', 'definition_hash' => $candidate['candidate_definition_sha256'], 'digest' => str_repeat('c', 64), 'fixture_hash' => str_repeat('b', 64),
            'formula' => ['assertion_codes' => ['formula.plan_fact.passed'], 'formula_version' => $contract->formulaVersion, 'passed' => true, 'totals_hash' => str_repeat('a', 64)],
            'generated_at' => '2026-08-01T00:00:00.000000Z',
            'source' => ['assertion_codes' => ['source.plan_fact.passed'], 'passed' => true, 'row_count' => 1, 'rows_hash' => str_repeat('e', 64), 'snapshot_id' => BudgetPlanFactCandidateFixture::closeId(), 'snapshot_kind' => 'budget.plan_fact.close', 'source_hash' => BudgetPlanFactCandidateContract::SOURCE_HASH],
            'source_schema_version' => $contract->sourceSchemaVersion, 'status' => 'passed',
        ];
        $export = static fn (string $format, string $renderer, string $value): array => ['format' => $format, 'schema_sha256' => str_repeat($value, 64), 'fixture_sha256' => str_repeat('b', 64), 'renderer_class' => $renderer, 'renderer_contract_sha256' => str_repeat($value, 64), 'renderer_sha256' => str_repeat($value, 64), 'assertion_codes' => ["export.{$format}.renderer.passed"]];
        $proof = [
            'binding_sha256' => str_repeat('7', 64), 'candidate_definition_sha256' => $candidate['candidate_definition_sha256'], 'candidate_manifest_sha256' => $this->hash($candidate),
            'ci' => ['commit_sha' => $this->commit(), 'completed_at_utc' => '2026-08-01T00:00:00.000000Z', 'required_checks' => ['binding_contract', 'drill_down_contract', 'export_csv_contract', 'export_xlsx_contract', 'formula_contract', 'postgresql_contract', 'rbac_contract', 'source_contract'], 'run_id' => 'bpf-ci', 'suite_sha256' => str_repeat('8', 64)],
            'code' => BudgetPlanFactCandidateContract::CODE, 'components' => [['class' => CsvReportExportRenderer::class, 'sha256' => str_repeat('9', 64)], ['class' => XlsxReportExportRenderer::class, 'sha256' => str_repeat('a', 64)]], 'conformance_evidence_sha256' => $conformance['digest'], 'contract_version' => '1.0.0',
            'drill_down_contract' => ['assertion_codes' => ['drill_down.schema.passed'], 'schema_sha256' => str_repeat('b', 64)], 'export_contracts' => [$export('csv', CsvReportExportRenderer::class, '1'), $export('xlsx', XlsxReportExportRenderer::class, '2')], 'fixture_sha256' => str_repeat('b', 64),
            'formula' => ['assertion_codes' => ['formula.plan_fact.passed'], 'formula_version' => $contract->formulaVersion, 'totals_sha256' => str_repeat('a', 64)],
            'permissions' => ['audit' => [], 'download' => ['budgeting.plan_fact.export'], 'export' => ['budgeting.plan_fact.export'], 'run' => ['budgeting.plan_fact.view'], 'sensitive' => [], 'view' => ['budgeting.plan_fact.view']],
            'release' => ['approver_identity' => 'bot@most', 'created_at_utc' => '2026-08-01T00:00:00.000000Z', 'git_sha' => $this->commit()], 'semantic_fingerprints' => ['formula' => BudgetPlanFactCandidateContract::FORMULA_HASH, 'source' => BudgetPlanFactCandidateContract::SOURCE_HASH],
            'source' => ['assertion_codes' => ['source.plan_fact.passed'], 'row_count' => 1, 'rows_sha256' => str_repeat('e', 64), 'snapshot_id' => BudgetPlanFactCandidateFixture::closeId(), 'snapshot_kind' => 'budget.plan_fact.close', 'source_sha256' => BudgetPlanFactCandidateContract::SOURCE_HASH], 'versions' => ['contract' => '1.0.0', 'formula' => $contract->formulaVersion, 'renderer' => '1.0.0', 'source_schema' => $contract->sourceSchemaVersion],
        ];

        return [$candidate, $conformance, $proof];
    }

    private function outputDirectory(string $name): string
    {
        $parent = $this->root.DIRECTORY_SEPARATOR.$name;
        mkdir($parent, 0700, true);

        return $parent.DIRECTORY_SEPARATOR.'v1';
    }

    private function assertRejected(string $directory, string $sha): void
    {
        try {
            (new BudgetPlanFactReleaseCandidateResolver)->resolve($directory, $sha);
            self::fail('Expected the candidate resolver to reject the artifact.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('budget_plan_fact_release_candidate_untrusted', $exception->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private function read(string $directory, string $file): array
    {
        return json_decode((string) file_get_contents($directory.DIRECTORY_SEPARATOR.$file), true, 64, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $document */
    private function write(string $directory, string $file, array $document): void
    {
        file_put_contents($directory.DIRECTORY_SEPARATOR.$file, CanonicalJson::encode($document));
    }

    /** @param array<string,mixed> $document */
    private function hash(array $document): string
    {
        return hash('sha256', CanonicalJson::encode($document));
    }

    private function commit(): string
    {
        return 'abcdefabcdefabcdefabcdefabcdefabcdefabcd';
    }

    private function delete(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        } foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $path = $directory.DIRECTORY_SEPARATOR.$entry;
                is_dir($path) ? $this->delete($path) : unlink($path);
            }
        } rmdir($directory);
    }
}
