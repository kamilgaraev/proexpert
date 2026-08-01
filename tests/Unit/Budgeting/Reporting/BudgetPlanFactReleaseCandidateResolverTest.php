<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting\Reporting;

use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationAdmissionRequirements;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationDeliveryContractHasher;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
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

        $proof = $this->proof($candidate, $conformance);
        $this->write(BudgetPlanFactReleaseCandidateLayout::PROOF_TEMPLATE, $proof);
        $this->write(
            BudgetPlanFactReleaseCandidateLayout::REQUEST_FILE,
            BudgetPlanFactReleaseCandidateLayout::request($this->commitSha(), ReportPublicationProof::fromArray($proof)->digest()->value),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('budget_plan_fact_release_candidate_untrusted');

        (new BudgetPlanFactReleaseCandidateResolver)->resolve($this->directory, $this->commitSha());
    }

    public function test_rejects_a_self_consistent_but_noncanonical_conformance_digest(): void
    {
        $candidate = $this->document(BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST);
        $conformance = $this->document(BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE);
        $conformance['digest'] = str_repeat('c', 64);
        $proof = $this->proof($candidate, $conformance);

        $this->write(BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE, $conformance);
        $this->write(BudgetPlanFactReleaseCandidateLayout::PROOF_TEMPLATE, $proof);
        $this->write(
            BudgetPlanFactReleaseCandidateLayout::REQUEST_FILE,
            BudgetPlanFactReleaseCandidateLayout::request($this->commitSha(), ReportPublicationProof::fromArray($proof)->digest()->value),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('budget_plan_fact_release_candidate_untrusted');

        (new BudgetPlanFactReleaseCandidateResolver)->resolve($this->directory, $this->commitSha());
    }

    public function test_rejects_self_consistent_proof_semantic_forges(): void
    {
        $forges = [
            'source rows' => static function (array &$proof): void {
                $proof['source']['rows_sha256'] = str_repeat('1', 64);
            },
            'formula totals' => static function (array &$proof): void {
                $proof['formula']['totals_sha256'] = str_repeat('2', 64);
            },
            'components' => static function (array &$proof): void {
                $proof['components'][0]['sha256'] = str_repeat('3', 64);
            },
            'assertions' => static function (array &$proof): void {
                $proof['source']['assertion_codes'] = ['source.forged.passed'];
            },
            'delivery renderer' => static function (array &$proof): void {
                $proof['export_contracts'][0]['renderer_sha256'] = str_repeat('4', 64);
            },
            'delivery assertions' => static function (array &$proof): void {
                $proof['export_contracts'][0]['assertion_codes'] = [
                    'export.csv.fixture.passed',
                    'export.csv.provenance.passed',
                    'export.csv.redaction.passed',
                    'export.csv.schema.passed',
                    'export.csv.zed.passed',
                ];
            },
            'required checks' => static function (array &$proof): void {
                $proof['ci']['required_checks'] = array_values(array_filter(
                    $proof['ci']['required_checks'],
                    static fn (string $check): bool => $check !== 'postgresql_contract',
                ));
            },
        ];

        foreach ($forges as $name => $forge) {
            $candidate = $this->document(BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST);
            $conformance = $this->document(BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE);
            $proof = $this->document(BudgetPlanFactReleaseCandidateLayout::PROOF_TEMPLATE);
            $forge($proof);
            $this->write(BudgetPlanFactReleaseCandidateLayout::PROOF_TEMPLATE, $proof);
            $this->write(
                BudgetPlanFactReleaseCandidateLayout::REQUEST_FILE,
                BudgetPlanFactReleaseCandidateLayout::request($this->commitSha(), ReportPublicationProof::fromArray($proof)->digest()->value),
            );

            try {
                (new BudgetPlanFactReleaseCandidateResolver)->resolve($this->directory, $this->commitSha());
                self::fail("{$name} forge was accepted");
            } catch (InvalidArgumentException $exception) {
                self::assertSame('budget_plan_fact_release_candidate_untrusted', $exception->getMessage());
            }

            $this->writeDocuments();
        }
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
            'component_class_hashes' => $this->componentHashes(),
            'contract_version' => '1.0.0',
            'definition_hash' => $candidate['candidate_definition_sha256'],
            'digest' => '',
            'fixture_hash' => str_repeat('b', 64),
            'formula' => ['assertion_codes' => ['formula.plan_fact.passed'], 'formula_version' => BudgetPlanFactCandidateContract::FORMULA_VERSION, 'passed' => true, 'totals_hash' => str_repeat('a', 64)],
            'generated_at' => '2026-08-01T00:00:00.000000Z',
            'source' => ['assertion_codes' => ['source.plan_fact.passed'], 'passed' => true, 'row_count' => 1, 'rows_hash' => str_repeat('e', 64), 'snapshot_id' => BudgetPlanFactCandidateFixture::closeId(), 'snapshot_kind' => 'budget.plan_fact.close', 'source_hash' => BudgetPlanFactCandidateContract::SOURCE_HASH],
            'source_schema_version' => BudgetPlanFactCandidateFixture::contract()->sourceSchemaVersion,
            'status' => 'passed',
        ];
        $conformance['digest'] = $this->conformanceDigest($conformance);
        $proof = $this->proof($candidate, $conformance);
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
            'readiness' => ['delivery' => 'verified', 'formula' => 'ready', 'publication' => 'candidate', 'source' => 'ready'],
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
    private function proof(array $candidate, array $conformance): array
    {
        return [
            'binding_sha256' => str_repeat('7', 64),
            'candidate_definition_sha256' => $candidate['candidate_definition_sha256'],
            'candidate_manifest_sha256' => hash('sha256', CanonicalJson::encode($candidate)),
            'ci' => ['commit_sha' => $this->commitSha(), 'completed_at_utc' => '2026-08-01T00:00:00.000000Z', 'required_checks' => ['binding_contract', 'drill_down_contract', 'export_csv_contract', 'export_xlsx_contract', 'formula_contract', 'postgresql_contract', 'rbac_contract', 'source_contract'], 'run_id' => 'ci-1', 'suite_sha256' => str_repeat('8', 64)],
            'code' => BudgetPlanFactCandidateContract::CODE,
            'components' => $conformance['component_class_hashes'],
            'conformance_evidence_sha256' => $conformance['digest'],
            'contract_version' => '1.0.0',
            'drill_down_contract' => ['assertion_codes' => ['drill_down.schema.passed'], 'schema_sha256' => ReportPublicationAdmissionRequirements::profileCatalog()->forCode(BudgetPlanFactCandidateContract::CODE)->drillDownSchemaHash],
            'export_contracts' => $this->deliveryContracts($candidate, $conformance),
            'fixture_sha256' => str_repeat('b', 64),
            'formula' => ['assertion_codes' => ['formula.plan_fact.passed'], 'formula_version' => BudgetPlanFactCandidateContract::FORMULA_VERSION, 'totals_sha256' => str_repeat('a', 64)],
            'permissions' => ['audit' => [], 'download' => ['budgeting.plan_fact.export'], 'export' => ['budgeting.plan_fact.export'], 'run' => ['budgeting.plan_fact.view'], 'sensitive' => [], 'view' => ['budgeting.plan_fact.view']],
            'release' => ['approver_identity' => 'bot@most', 'created_at_utc' => '2026-08-01T00:00:00.000000Z', 'git_sha' => $this->commitSha()],
            'semantic_fingerprints' => $this->fingerprints($candidate, $conformance),
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

    /** @param array<string, mixed> $document */
    private function conformanceDigest(array $document): string
    {
        unset($document['digest']);

        return hash('sha256', CanonicalJson::encode($document));
    }

    /** @return list<array{class: class-string, sha256: string}> */
    private function componentHashes(): array
    {
        $classes = [
            CsvReportExportRenderer::class,
            XlsxReportExportRenderer::class,
            PlanFactCalculator::class,
            PlanFactSourceSnapshotMaterializer::class,
        ];
        sort($classes, SORT_STRING);

        return array_map(
            static fn (string $class): array => [
                'class' => $class,
                'sha256' => hash_file('sha256', (string) (new \ReflectionClass($class))->getFileName()),
            ],
            $classes,
        );
    }

    /** @param array<string, mixed> $candidate @param array<string, mixed> $conformance */
    private function fingerprints(array $candidate, array $conformance): array
    {
        $formula = hash('sha256', CanonicalJson::encode([
            'component_class_hashes' => $conformance['component_class_hashes'],
            'totals_hash' => $conformance['formula']['totals_hash'],
        ]));
        $source = hash('sha256', CanonicalJson::encode([
            'filters' => $candidate['candidate_definition']['filters'],
            'grain' => null,
            'source_hash' => $conformance['source']['source_hash'],
        ]));

        return ['formula' => $formula, 'source' => $source];
    }

    /** @param array<string, mixed> $candidate @param array<string, mixed> $conformance */
    private function deliveryContracts(array $candidate, array $conformance): array
    {
        $components = [];
        foreach ($conformance['component_class_hashes'] as $component) {
            $components[$component['class']] = $component['sha256'];
        }
        $profile = ReportPublicationAdmissionRequirements::profileCatalog()->forCode(BudgetPlanFactCandidateContract::CODE);
        $contracts = [];
        foreach ($profile->exports as $format => $contract) {
            $renderer = $contract['renderer_class'];
            $assertions = [
                "export.{$format}.fixture.passed",
                "export.{$format}.provenance.passed",
                "export.{$format}.redaction.passed",
                "export.{$format}.renderer.passed",
                "export.{$format}.schema.passed",
            ];
            $rendererHash = new Sha256Hash($components[$renderer]);
            $schemaHash = new Sha256Hash($contract['schema_sha256']);
            $fixtureHash = new Sha256Hash($conformance['fixture_hash']);
            $contracts[] = [
                'format' => $format,
                'schema_sha256' => $schemaHash->value,
                'fixture_sha256' => $fixtureHash->value,
                'renderer_class' => $renderer,
                'renderer_contract_sha256' => (new ReportPublicationDeliveryContractHasher)->hash(
                    $format,
                    $renderer,
                    $rendererHash,
                    $candidate['candidate_definition']['versions']['renderer'],
                    $schemaHash,
                    $fixtureHash,
                    $assertions,
                )->value,
                'renderer_sha256' => $rendererHash->value,
                'assertion_codes' => $assertions,
            ];
        }

        return $contracts;
    }
}
