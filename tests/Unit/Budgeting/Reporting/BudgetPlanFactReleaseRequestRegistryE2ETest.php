<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting\Reporting;

use App\BusinessModules\Core\Reporting\Application\Publication\BudgetPlanFactReleaseCandidateResolverAdapter;
use App\BusinessModules\Core\Reporting\Application\Publication\ProjectReportPublicationReleaseRequestRegistry;
use App\BusinessModules\Core\Reporting\Application\Publication\ProjectReportPublicationReleaseRequestRegistryFactory;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportDefinitionSemanticFingerprint;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationBindingHasher;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationDeliveryContractHasher;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseBindingFactory;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseDispatch;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseDispatchProfileCatalog;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseEligibilityGate;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseRequest;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFormulaConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Infrastructure\Conformance\FilesystemReportConformanceEvidenceRepository;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactReleaseCandidateLayout;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactReleaseCandidateResolver;
use App\BusinessModules\Features\Budgeting\Services\PlanFactCalculator;
use App\BusinessModules\Features\Budgeting\Services\PlanFactReportSourceSnapshotAdapter;
use App\BusinessModules\Features\Budgeting\Services\PlanFactSourceSnapshotMaterializer;
use DateTimeImmutable;
use InvalidArgumentException;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\Budgeting\BudgetPlanFactCandidateFixture;

final class BudgetPlanFactReleaseRequestRegistryE2ETest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-budget-plan-fact-release-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->delete($this->root);
        parent::tearDown();
    }

    public function test_resolves_a_common_proof_and_filesystem_evidence_for_the_sealed_close(): void
    {
        [$registry, $request] = $this->registryAndRequest();

        $resolved = $registry->resolve($request);

        self::assertSame(BudgetPlanFactCandidateContract::CODE, $resolved->admission->candidate->code);
        self::assertSame(BudgetPlanFactCandidateFixture::closeId(), $resolved->admission->evidence->source->snapshotId);
        self::assertSame(['csv', 'xlsx'], $resolved->admission->candidate->definition->formats);
    }

    public function test_rejects_missing_tampered_wrong_close_and_cross_code_requests(): void
    {
        [$registry, $request] = $this->registryAndRequest();
        unlink($this->root.DIRECTORY_SEPARATOR.BudgetPlanFactReleaseCandidateLayout::PROOF_TEMPLATE);
        $this->assertUntrusted(fn () => $registry->resolve($request));

        [$registry, $request] = $this->registryAndRequest();
        file_put_contents($this->root.DIRECTORY_SEPARATOR.BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST, '{}');
        $this->assertUntrusted(fn () => $registry->resolve($request));

        [$registry, $request] = $this->registryAndRequest(closeId: '01KAAAAAAAAAAAAAAAAAAAAAAA');
        $this->assertUntrusted(fn () => $registry->resolve($request));

        [$registry] = $this->registryAndRequest();
        $this->assertUntrusted(fn () => $registry->resolve(ReportPublicationReleaseRequest::fromArray([
            ...BudgetPlanFactReleaseCandidateLayout::request($this->commit(), str_repeat('a', 64)),
            'code' => 'procurement_cycle',
        ])));
    }

    /** @return array{ProjectReportPublicationReleaseRequestRegistry, ReportPublicationReleaseRequest} */
    private function registryAndRequest(?string $closeId = null): array
    {
        $this->delete($this->root);
        mkdir($this->root, 0700, true);
        $closeId ??= BudgetPlanFactCandidateFixture::closeId();
        $factory = new ReportDefinitionFactory;
        $base = $this->definition();
        $adapter = (new ReflectionClass(PlanFactReportSourceSnapshotAdapter::class))->newInstanceWithoutConstructor();
        $temporary = $factory->fromManifest($base);
        $temporaryBinding = new ReportDefinitionBinding($temporary->code, $temporary->definitionHash, $temporary->contractVersion, $adapter, $adapter, $adapter, null);
        $temporaryEvidence = $this->evidence($temporary, $temporaryBinding, $closeId);
        $fingerprints = new ReportDefinitionSemanticFingerprint;
        $base['semantic_fingerprints'] = [
            'formula' => $fingerprints->formula($temporaryEvidence),
            'source' => $fingerprints->source($base, $temporaryEvidence),
        ];
        $definition = $factory->fromManifest($base);
        $binding = new ReportDefinitionBinding($definition->code, $definition->definitionHash, $definition->contractVersion, $adapter, $adapter, $adapter, null);
        $evidence = $this->evidence($definition, $binding, $closeId);
        $candidate = [
            'candidate_definition' => $base,
            'candidate_definition_sha256' => $definition->definitionHash->value,
            'code' => BudgetPlanFactCandidateContract::CODE,
            'formula_sha256' => BudgetPlanFactCandidateContract::FORMULA_HASH,
            'formula_version' => BudgetPlanFactCandidateContract::FORMULA_VERSION,
            'generated_from_commit' => $this->commit(),
            'publication_status' => 'candidate',
            'source_close_id' => BudgetPlanFactCandidateFixture::closeId(),
            'source_close_identity' => BudgetPlanFactCandidateFixture::closeIdentity()->toArray(),
            'source_close_identity_sha256' => hash('sha256', CanonicalJson::encode(BudgetPlanFactCandidateFixture::closeIdentity()->toArray())),
            'source_schema_version' => $definition->sourceSchemaVersion,
            'source_sha256' => BudgetPlanFactCandidateContract::SOURCE_HASH,
        ];
        $proof = $this->proof($definition, $binding, $evidence, $candidate);
        $documents = [
            BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST => $candidate,
            BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE => [...$evidence->canonicalPayload(), 'digest' => $evidence->digest()->value],
            BudgetPlanFactReleaseCandidateLayout::PROOF_TEMPLATE => $proof->payload(),
            BudgetPlanFactReleaseCandidateLayout::REQUEST_FILE => BudgetPlanFactReleaseCandidateLayout::request($this->commit(), $proof->digest()->value),
        ];
        foreach ($documents as $name => $document) {
            file_put_contents($this->root.DIRECTORY_SEPARATOR.$name, CanonicalJson::encode($document));
        }
        $evidenceRoot = $this->root.DIRECTORY_SEPARATOR.'evidence';
        mkdir($evidenceRoot.DIRECTORY_SEPARATOR.'docs/reports/contracts', 0700, true);
        copy(dirname(__DIR__, 4).'/docs/reports/contracts/report-conformance-evidence.schema.json', $evidenceRoot.'/docs/reports/contracts/report-conformance-evidence.schema.json');
        $repository = new FilesystemReportConformanceEvidenceRepository($evidenceRoot, new Draft202012SchemaValidator(new CompliantValidator));
        $repository->put($evidence);
        $profile = ProjectReportPublicationReleaseRequestRegistryFactory::profiles()->forCode(BudgetPlanFactCandidateContract::CODE);
        $dispatches = new ReportPublicationReleaseDispatchProfileCatalog([
            new ReportPublicationReleaseDispatch($profile, new BudgetPlanFactReleaseCandidateResolverAdapter(new BudgetPlanFactReleaseCandidateResolver), new class($binding) implements ReportPublicationReleaseBindingFactory
            {
                public function __construct(private ReportDefinitionBinding $binding) {}

                public function create(ReportDefinition $definition): ReportDefinitionBinding
                {
                    return $this->binding;
                }
            }),
        ]);
        $registry = new ProjectReportPublicationReleaseRequestRegistry(
            $this->root,
            '{}',
            new Sha256Hash(hash('sha256', '{}')),
            $dispatches,
            $factory,
            $repository,
            $this->createStub(ReportPublicationReleaseEligibilityGate::class),
            $this->createStub(ReportPublicationRegistry::class),
        );

        return [$registry, ReportPublicationReleaseRequest::fromArray($documents[BudgetPlanFactReleaseCandidateLayout::REQUEST_FILE])];
    }

    private function proof(ReportDefinition $definition, ReportDefinitionBinding $binding, ReportDefinitionConformanceEvidence $evidence, array $candidate): ReportPublicationProof
    {
        $profile = ProjectReportPublicationReleaseRequestRegistryFactory::profiles()->forCode(BudgetPlanFactCandidateContract::CODE);
        $contracts = \App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationAdmissionRequirements::profileCatalog()->forCode(BudgetPlanFactCandidateContract::CODE);
        $exports = [];
        foreach ($contracts->exports as $format => $contract) {
            $class = $contract['renderer_class'];
            $assertions = ["export.{$format}.fixture.passed", "export.{$format}.provenance.passed", "export.{$format}.redaction.passed", "export.{$format}.renderer.passed", "export.{$format}.schema.passed"];
            $exports[] = ['format' => $format, 'schema_sha256' => $contract['schema_sha256'], 'fixture_sha256' => $evidence->fixtureHash->value, 'renderer_class' => $class, 'renderer_contract_sha256' => (new ReportPublicationDeliveryContractHasher)->hash($format, $class, $evidence->componentClassHashes[$class], $definition->rendererVersion, new Sha256Hash($contract['schema_sha256']), $evidence->fixtureHash, $assertions)->value, 'renderer_sha256' => $evidence->componentClassHashes[$class]->value, 'assertion_codes' => $assertions];
        }
        $components = [];
        foreach ($evidence->componentClassHashes as $class => $hash) {
            $components[] = ['class' => $class, 'sha256' => $hash->value];
        }
        $fingerprints = new ReportDefinitionSemanticFingerprint;
        $checks = $contracts->requiredChecks;

        return ReportPublicationProof::fromArray([
            'code' => $definition->code, 'candidate_manifest_sha256' => hash('sha256', CanonicalJson::encode($candidate)), 'candidate_definition_sha256' => $definition->definitionHash->value, 'binding_sha256' => (new ReportPublicationBindingHasher)->hash($binding, $evidence)->value, 'contract_version' => $definition->contractVersion,
            'versions' => ['source_schema' => $definition->sourceSchemaVersion, 'formula' => $definition->formulaVersion, 'contract' => $definition->contractVersion, 'renderer' => $definition->rendererVersion],
            'semantic_fingerprints' => ['source' => $fingerprints->source($candidate['candidate_definition'], $evidence), 'formula' => $fingerprints->formula($evidence)], 'fixture_sha256' => $evidence->fixtureHash->value, 'conformance_evidence_sha256' => $evidence->digest()->value,
            'source' => ['snapshot_kind' => $evidence->source->snapshotKind, 'snapshot_id' => $evidence->source->snapshotId, 'source_sha256' => $evidence->source->sourceHash->value, 'rows_sha256' => $evidence->source->rowsHash->value, 'row_count' => $evidence->source->rowCount, 'assertion_codes' => $evidence->source->assertionCodes],
            'formula' => ['formula_version' => $evidence->formula->formulaVersion, 'totals_sha256' => $evidence->formula->totalsHash->value, 'assertion_codes' => $evidence->formula->assertionCodes], 'components' => $components,
            'permissions' => ['view' => ['budgeting.plan_fact.view'], 'run' => ['budgeting.plan_fact.view'], 'export' => ['budgeting.plan_fact.export'], 'download' => ['budgeting.plan_fact.export'], 'sensitive' => [], 'audit' => []], 'export_contracts' => $exports, 'drill_down_contract' => ['schema_sha256' => $contracts->drillDownSchemaHash, 'assertion_codes' => ['drill_down.schema.passed']],
            'ci' => ['run_id' => 'budget-plan-fact-ci', 'commit_sha' => $this->commit(), 'suite_sha256' => hash('sha256', 'budget-plan-fact-suite'), 'completed_at_utc' => '2026-08-01T01:00:00.000000Z', 'required_checks' => $checks], 'release' => ['git_sha' => $this->commit(), 'created_at_utc' => '2026-08-01T01:00:00.000000Z', 'approver_identity' => 'release-bot@most'],
        ]);
    }

    private function evidence(ReportDefinition $definition, ReportDefinitionBinding $binding, string $closeId): ReportDefinitionConformanceEvidence
    {
        $classes = [PlanFactCalculator::class, PlanFactReportSourceSnapshotAdapter::class, PlanFactSourceSnapshotMaterializer::class, CsvReportExportRenderer::class, XlsxReportExportRenderer::class];
        $hashes = [];
        foreach ($classes as $class) {
            $hashes[$class] = new Sha256Hash((string) hash_file('sha256', (string) (new ReflectionClass($class))->getFileName()));
        }

        return new ReportDefinitionConformanceEvidence($definition->code, $definition->definitionHash, $definition->contractVersion, $definition->sourceSchemaVersion, new Sha256Hash(hash('sha256', 'budget-plan-fact-fixture')), new ReportSourceConformanceEvidence(new Sha256Hash(BudgetPlanFactCandidateContract::SOURCE_HASH), 'budget.plan_fact.close', $closeId, 2, new Sha256Hash(hash('sha256', 'budget-plan-fact-rows')), true, ['source.plan_fact.passed']), new ReportFormulaConformanceEvidence($definition->formulaVersion, new Sha256Hash(hash('sha256', 'budget-plan-fact-totals')), true, ['formula.plan_fact.passed']), $hashes, 2, 'passed', $this->commit(), new DateTimeImmutable('2026-08-01T00:00:00.000000Z'));
    }

    private function definition(): array
    {
        $contract = new BudgetPlanFactCandidateContract;

        return ['capabilities' => ['supports_subscriptions' => false], 'code' => BudgetPlanFactCandidateContract::CODE, 'columns' => $contract->columns(), 'filters' => $contract->filters(), 'formats' => $contract->formats(), 'permissions' => ['audit' => [], 'export' => ['budgeting.plan_fact.export'], 'sensitive' => [], 'view' => ['budgeting.plan_fact.view']], 'readiness' => ['delivery' => 'verified', 'formula' => 'verified', 'publication' => 'candidate', 'source' => 'verified'], 'sorts' => $contract->sorts(), 'versions' => ['contract' => '1.0.0', 'formula' => BudgetPlanFactCandidateContract::FORMULA_VERSION, 'renderer' => '1.0.0', 'source_schema' => $contract->sourceSchemaVersion]];
    }

    private function assertUntrusted(\Closure $operation): void
    {
        try {
            $operation();
            self::fail('Expected trusted-root rejection.');
        } catch (InvalidArgumentException $exception) {
            self::assertContains($exception->getMessage(), ['budget_plan_fact_release_candidate_untrusted', 'report_publication_release_request_untrusted', 'report_publication_release_input_invalid']);
        }
    }

    private function commit(): string
    {
        return 'abcdefabcdefabcdefabcdefabcdefabcdefabcd';
    }

    private function delete(string $path): void
    {
        if (! is_dir($path)) {
            return;
        } foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $child = $path.DIRECTORY_SEPARATOR.$entry;
                is_dir($child) ? $this->delete($child) : unlink($child);
            }
        } rmdir($path);
    }
}
