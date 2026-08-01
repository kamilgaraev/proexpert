<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactReleaseCandidateLayout;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactReleaseCandidateResolver;
use App\BusinessModules\Features\Budgeting\Services\PlanFactCalculator;
use App\BusinessModules\Features\Budgeting\Services\PlanFactSourceSnapshotMaterializer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Budgeting\BudgetPlanFactCandidateFixture;

final class BudgetPlanFactReleaseCandidateResolverTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-budget-plan-fact-candidate-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        $this->writeDocuments();
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($this->directory.DIRECTORY_SEPARATOR.$entry);
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_resolves_only_the_canonical_budget_plan_fact_layout(): void
    {
        $documents = (new BudgetPlanFactReleaseCandidateResolver)->resolve(
            $this->directory,
            $this->commitSha(),
        );

        self::assertSame(BudgetPlanFactCandidateContract::CODE, $documents[BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST]['code']);
        self::assertSame(
            BudgetPlanFactCandidateContract::FORMULA_VERSION,
            $documents[BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE]['formula']['formula_version'],
        );
    }

    /** @dataProvider driftedDocumentProvider */
    public function test_rejects_candidate_identity_drift(string $document, string $key, mixed $value): void
    {
        $payload = $this->document($document);
        $payload[$key] = $value;
        $this->write($document, $payload);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('budget_plan_fact_release_candidate_untrusted');

        (new BudgetPlanFactReleaseCandidateResolver)->resolve(
            $this->directory,
            $this->commitSha(),
        );
    }

    public static function driftedDocumentProvider(): array
    {
        return [
            'formula version' => [BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE, 'formula', []],
            'source hash' => [BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE, 'source', []],
            'close identity' => [BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST, 'source_close_identity_sha256', str_repeat('0', 64)],
            'candidate hash' => [BudgetPlanFactReleaseCandidateLayout::PROOF_TEMPLATE, 'candidate_manifest_sha256', str_repeat('0', 64)],
        ];
    }

    public function test_rejects_a_self_consistent_but_malformed_close_identity(): void
    {
        $candidate = $this->document(BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST);
        $candidate['source_close_identity']['organization_id'] = 0;
        $candidate['source_close_identity_sha256'] = hash('sha256', CanonicalJson::encode($candidate['source_close_identity']));
        $this->write(BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST, $candidate);

        $conformance = $this->document(BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE);
        $this->write(BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE, $conformance);

        $proof = $this->proof($candidate, $conformance['digest']);
        $this->write(BudgetPlanFactReleaseCandidateLayout::PROOF_TEMPLATE, $proof);
        $this->write(
            BudgetPlanFactReleaseCandidateLayout::REQUEST_FILE,
            BudgetPlanFactReleaseCandidateLayout::request($this->commitSha(), ReportPublicationProof::fromArray($proof)->digest()->value),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('budget_plan_fact_release_candidate_untrusted');

        (new BudgetPlanFactReleaseCandidateResolver)->resolve($this->directory, $this->commitSha());
    }

    private function writeDocuments(): void
    {
        $commit = $this->commitSha();
        $definition = $this->definition();
        $close = [
            'plan_identity' => 'budget-1',
            'organization_id' => 1,
            'period_end' => '2026-01-31',
            'period_start' => '2026-01-01',
            'scenario_identity' => 'scenario-1',
        ];
        $candidate = [
            'candidate_definition' => $definition,
            'candidate_definition_sha256' => hash('sha256', CanonicalJson::encode($definition)),
            'code' => BudgetPlanFactCandidateContract::CODE,
            'formula_sha256' => BudgetPlanFactCandidateContract::FORMULA_HASH,
            'formula_version' => BudgetPlanFactCandidateContract::FORMULA_VERSION,
            'generated_from_commit' => $commit,
            'publication_status' => 'candidate',
            'source_close_id' => BudgetPlanFactCandidateFixture::closeId(),
            'source_close_identity' => $close,
            'source_close_identity_sha256' => hash('sha256', CanonicalJson::encode($close)),
            'source_schema_version' => BudgetPlanFactCandidateFixture::contract()->sourceSchemaVersion,
            'source_sha256' => BudgetPlanFactCandidateContract::SOURCE_HASH,
        ];
        $conformance = [
            'assertion_count' => 2,
            'code' => BudgetPlanFactCandidateContract::CODE,
            'commit_sha' => $commit,
            'component_class_hashes' => [
                ['class' => PlanFactCalculator::class, 'sha256' => BudgetPlanFactCandidateContract::FORMULA_HASH],
                ['class' => PlanFactSourceSnapshotMaterializer::class, 'sha256' => BudgetPlanFactCandidateContract::SOURCE_HASH],
            ],
            'contract_version' => '1.0.0',
            'definition_hash' => $candidate['candidate_definition_sha256'],
            'digest' => str_repeat('c', 64),
            'fixture_hash' => str_repeat('b', 64),
            'formula' => ['assertion_codes' => ['formula.plan_fact.passed'], 'formula_version' => BudgetPlanFactCandidateContract::FORMULA_VERSION, 'passed' => true, 'totals_hash' => str_repeat('a', 64)],
            'generated_at' => '2026-08-01T00:00:00.000000Z',
            'source' => ['assertion_codes' => ['source.plan_fact.passed'], 'passed' => true, 'row_count' => 1, 'rows_hash' => str_repeat('e', 64), 'snapshot_id' => BudgetPlanFactCandidateFixture::closeId(), 'snapshot_kind' => 'budget.plan_fact.close', 'source_hash' => BudgetPlanFactCandidateContract::SOURCE_HASH],
            'source_schema_version' => BudgetPlanFactCandidateFixture::contract()->sourceSchemaVersion,
            'status' => 'passed',
        ];
        $proof = $this->proof($candidate, $conformance['digest']);
        $request = BudgetPlanFactReleaseCandidateLayout::request($commit, ReportPublicationProof::fromArray($proof)->digest()->value);

        $this->write(BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST, $candidate);
        $this->write(BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE, $conformance);
        $this->write(BudgetPlanFactReleaseCandidateLayout::PROOF_TEMPLATE, $proof);
        $this->write(BudgetPlanFactReleaseCandidateLayout::REQUEST_FILE, $request);
    }

    private function definition(): array
    {
        $contract = BudgetPlanFactCandidateFixture::contract();

        return [
            'capabilities' => ['supports_subscriptions' => false],
            'code' => BudgetPlanFactCandidateContract::CODE,
            'columns' => $this->canonicalItems($contract->columns()),
            'filters' => $this->canonicalItems($contract->filters()),
            'formats' => $contract->formats(),
            'permissions' => [
                'audit' => [],
                'export' => ['budgeting.plan_fact.export'],
                'sensitive' => [],
                'view' => ['budgeting.plan_fact.view'],
            ],
            'readiness' => ['delivery' => 'verified', 'formula' => 'verified', 'publication' => 'candidate', 'source' => 'verified'],
            'sorts' => $this->canonicalItems($contract->sorts()),
            'versions' => [
                'contract' => '1.0.0',
                'formula' => BudgetPlanFactCandidateContract::FORMULA_VERSION,
                'renderer' => '1.0.0',
                'source_schema' => $contract->sourceSchemaVersion,
            ],
        ];
    }

    /** @param array<string, mixed> $candidate */
    private function proof(array $candidate, string $conformanceDigest): array
    {
        $export = static fn (string $format, string $renderer): array => [
            'format' => $format,
            'schema_sha256' => str_repeat($format === 'csv' ? '1' : '2', 64),
            'fixture_sha256' => str_repeat('b', 64),
            'renderer_class' => $renderer,
            'renderer_contract_sha256' => str_repeat($format === 'csv' ? '3' : '4', 64),
            'renderer_sha256' => str_repeat($format === 'csv' ? '5' : '6', 64),
            'assertion_codes' => ['export.'.$format.'.renderer.passed'],
        ];

        return [
            'binding_sha256' => str_repeat('7', 64),
            'candidate_definition_sha256' => $candidate['candidate_definition_sha256'],
            'candidate_manifest_sha256' => hash('sha256', CanonicalJson::encode($candidate)),
            'ci' => ['commit_sha' => $this->commitSha(), 'completed_at_utc' => '2026-08-01T00:00:00.000000Z', 'required_checks' => ['binding_contract', 'drill_down_contract', 'export_csv_contract', 'export_xlsx_contract', 'formula_contract', 'postgresql_contract', 'rbac_contract', 'source_contract'], 'run_id' => 'ci-1', 'suite_sha256' => str_repeat('8', 64)],
            'code' => BudgetPlanFactCandidateContract::CODE,
            'components' => [['class' => CsvReportExportRenderer::class, 'sha256' => str_repeat('9', 64)], ['class' => XlsxReportExportRenderer::class, 'sha256' => str_repeat('a', 64)]],
            'conformance_evidence_sha256' => $conformanceDigest,
            'contract_version' => '1.0.0',
            'drill_down_contract' => ['assertion_codes' => ['drill_down.schema.passed'], 'schema_sha256' => str_repeat('b', 64)],
            'export_contracts' => [$export('csv', CsvReportExportRenderer::class), $export('xlsx', XlsxReportExportRenderer::class)],
            'fixture_sha256' => str_repeat('b', 64),
            'formula' => ['assertion_codes' => ['formula.plan_fact.passed'], 'formula_version' => BudgetPlanFactCandidateContract::FORMULA_VERSION, 'totals_sha256' => str_repeat('a', 64)],
            'permissions' => ['audit' => [], 'download' => ['budgeting.plan_fact.export'], 'export' => ['budgeting.plan_fact.export'], 'run' => ['budgeting.plan_fact.view'], 'sensitive' => [], 'view' => ['budgeting.plan_fact.view']],
            'release' => ['approver_identity' => 'bot@most', 'created_at_utc' => '2026-08-01T00:00:00.000000Z', 'git_sha' => $this->commitSha()],
            'semantic_fingerprints' => ['formula' => BudgetPlanFactCandidateContract::FORMULA_HASH, 'source' => BudgetPlanFactCandidateContract::SOURCE_HASH],
            'source' => ['assertion_codes' => ['source.plan_fact.passed'], 'row_count' => 1, 'rows_sha256' => str_repeat('e', 64), 'snapshot_id' => BudgetPlanFactCandidateFixture::closeId(), 'snapshot_kind' => 'budget.plan_fact.close', 'source_sha256' => BudgetPlanFactCandidateContract::SOURCE_HASH],
            'versions' => ['contract' => '1.0.0', 'formula' => BudgetPlanFactCandidateContract::FORMULA_VERSION, 'renderer' => '1.0.0', 'source_schema' => BudgetPlanFactCandidateFixture::contract()->sourceSchemaVersion],
        ];
    }

    private function commitSha(): string
    {
        return str_repeat('a', 40);
    }

    private function document(string $name): array
    {
        return json_decode((string) file_get_contents($this->directory.DIRECTORY_SEPARATOR.$name), true, 64, JSON_THROW_ON_ERROR);
    }

    private function write(string $name, array $document): void
    {
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.$name, CanonicalJson::encode($document));
    }

    private function canonicalItems(array $items): array
    {
        return array_map(
            static fn (array $item): array => json_decode(CanonicalJson::encode($item), true, 64, JSON_THROW_ON_ERROR),
            $items,
        );
    }
}
