<?php

declare(strict_types=1);

namespace Tests\Support\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Catalog\ReportPermissionCatalog;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportDefinitionSemanticFingerprint;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportDefinitionVersionPolicy;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationBindingHasher;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationDeliveryContractHasher;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationEligibilityService;
use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseIdentity;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Services\PlanFactReportSourceSnapshotAdapter;
use DateTimeImmutable;
use LogicException;
use ReflectionClass;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Reporting\CatalogBindingTestFactory;

final class ReportPublicationFixtureFactory
{
    public static function eligible(
        string $exportSchemaDigit = 'e',
        ?DateTimeImmutable $releaseAt = null,
        ?DateTimeImmutable $ciCompletedAt = null,
        bool $productionComponents = false,
    ): array {
        $releaseAt ??= new DateTimeImmutable('2026-08-01T02:03:04.654321+00:00');
        $ciCompletedAt ??= new DateTimeImmutable('2026-08-01T01:02:03.123456+00:00');
        $document = Yaml::parseFile(dirname(__DIR__, 4).'/tests/Fixtures/Reporting/Publication/candidate.valid.yaml');
        $fixtureHash = new Sha256Hash(
            $productionComponents ? hash('sha256', 'publication-fixture') : str_repeat('6', 64),
        );
        $exportSchemaHash = $productionComponents
            ? hash('sha256', 'export-schema-'.$exportSchemaDigit)
            : str_repeat($exportSchemaDigit, 64);
        $drillDownSchemaHash = $productionComponents
            ? hash('sha256', 'drill-down-schema')
            : str_repeat('2', 64);
        $row = $document['definitions'][0];
        $factory = new ReportDefinitionFactory;
        $temporary = $factory->fromManifest($row);
        $temporaryBinding = self::binding($temporary, $productionComponents);
        $temporaryEvidence = CatalogBindingTestFactory::evidence(
            $temporary,
            $temporaryBinding,
            $fixtureHash,
            productionHashes: $productionComponents,
        );
        $temporaryEvidence = self::withXlsxRenderer(
            $temporary,
            $temporaryBinding,
            $temporaryEvidence,
            $productionComponents,
        );
        $fingerprints = new ReportDefinitionSemanticFingerprint;
        $row['semantic_fingerprints'] = [
            'formula' => $fingerprints->formula($temporaryEvidence),
            'source' => $fingerprints->source($row, $temporaryEvidence),
        ];
        $definition = $factory->fromManifest($row);
        $binding = self::binding($definition, $productionComponents);
        $evidence = CatalogBindingTestFactory::evidence(
            $definition,
            $binding,
            $fixtureHash,
            productionHashes: $productionComponents,
        );
        $evidence = self::withXlsxRenderer($definition, $binding, $evidence, $productionComponents);
        $requiredChecks = self::requiredChecks();
        $ciPayload = [
            'checks' => array_fill_keys($requiredChecks, 'passed'),
            'commit_sha' => $evidence->commitSha,
            'completed_at_utc' => $ciCompletedAt->format('Y-m-d\TH:i:s.u\Z'),
            'run_id' => 'ci-1001',
        ];
        $ciEvidenceBytes = CanonicalJson::encode($ciPayload);
        $components = [];
        foreach ($evidence->componentClassHashes as $class => $hash) {
            $components[] = ['class' => $class, 'sha256' => $hash->value];
        }
        $candidateManifestHash = new Sha256Hash(
            $productionComponents ? hash('sha256', 'candidate-manifest-bytes') : str_repeat('1', 64),
        );
        $officialManifestHash = new Sha256Hash(
            $productionComponents ? hash('sha256', 'official-manifest-bytes') : str_repeat('0', 64),
        );
        $release = new ReportPublicationReleaseIdentity(
            $evidence->commitSha,
            $releaseAt,
            'release-bot@most',
        );
        $rendererHash = $evidence->componentClassHashes[XlsxReportExportRenderer::class];
        $rendererAssertions = [
            'export.xlsx.fixture.passed',
            'export.xlsx.provenance.passed',
            'export.xlsx.redaction.passed',
            'export.xlsx.renderer.passed',
            'export.xlsx.schema.passed',
        ];
        $rendererContractHash = (new ReportPublicationDeliveryContractHasher)->hash(
            'xlsx',
            XlsxReportExportRenderer::class,
            $rendererHash,
            $definition->rendererVersion,
            new Sha256Hash($exportSchemaHash),
            $evidence->fixtureHash,
            $rendererAssertions,
        );
        $proof = ReportPublicationProof::fromArray([
            'code' => $definition->code,
            'candidate_manifest_sha256' => $candidateManifestHash->value,
            'candidate_definition_sha256' => $definition->definitionHash->value,
            'binding_sha256' => (new ReportPublicationBindingHasher)->hash($binding, $evidence)->value,
            'contract_version' => $definition->contractVersion,
            'versions' => [
                'source_schema' => $definition->sourceSchemaVersion,
                'formula' => $definition->formulaVersion,
                'contract' => $definition->contractVersion,
                'renderer' => $definition->rendererVersion,
            ],
            'semantic_fingerprints' => [
                'source' => $row['semantic_fingerprints']['source'],
                'formula' => $row['semantic_fingerprints']['formula'],
            ],
            'fixture_sha256' => $evidence->fixtureHash->value,
            'conformance_evidence_sha256' => $evidence->digest()->value,
            'source' => [
                'snapshot_kind' => $evidence->source->snapshotKind,
                'snapshot_id' => $evidence->source->snapshotId,
                'source_sha256' => $evidence->source->sourceHash->value,
                'rows_sha256' => $evidence->source->rowsHash->value,
                'row_count' => $evidence->source->rowCount,
                'assertion_codes' => $evidence->source->assertionCodes,
            ],
            'formula' => [
                'formula_version' => $evidence->formula->formulaVersion,
                'totals_sha256' => $evidence->formula->totalsHash->value,
                'assertion_codes' => $evidence->formula->assertionCodes,
            ],
            'components' => $components,
            'permissions' => [
                'view' => $definition->permissionPolicy->viewPermissions,
                'run' => $definition->permissionPolicy->viewPermissions,
                'export' => $definition->permissionPolicy->exportPermissions,
                'download' => $definition->permissionPolicy->exportPermissions,
                'sensitive' => $definition->permissionPolicy->sensitivePermissions,
                'audit' => $definition->permissionPolicy->auditPermissions,
            ],
            'export_contracts' => [[
                'format' => 'xlsx',
                'schema_sha256' => $exportSchemaHash,
                'fixture_sha256' => $evidence->fixtureHash->value,
                'renderer_class' => XlsxReportExportRenderer::class,
                'renderer_contract_sha256' => $rendererContractHash->value,
                'renderer_sha256' => $rendererHash->value,
                'assertion_codes' => $rendererAssertions,
            ]],
            'drill_down_contract' => [
                'schema_sha256' => $drillDownSchemaHash,
                'assertion_codes' => ['drill_down.schema.passed'],
            ],
            'ci' => [
                'run_id' => $ciPayload['run_id'],
                'commit_sha' => $ciPayload['commit_sha'],
                'suite_sha256' => hash('sha256', $ciEvidenceBytes),
                'completed_at_utc' => $ciPayload['completed_at_utc'],
                'required_checks' => $requiredChecks,
            ],
            'release' => [
                'git_sha' => $release->gitSha,
                'created_at_utc' => $release->createdAtUtc(),
                'approver_identity' => $release->approverIdentity,
            ],
        ]);
        $candidate = new CandidateReportDefinition($definition);
        $eligibilityService = new ReportPublicationEligibilityService(
            new ReportPermissionCatalog,
            new ReportDefinitionVersionPolicy,
            new ReportPublicationBindingHasher,
            [$candidate->code => $requiredChecks],
            [
                $candidate->code => [
                    'drill_down_schema_sha256' => $drillDownSchemaHash,
                    'exports' => [
                        'xlsx' => [
                            'schema_sha256' => $exportSchemaHash,
                            'renderer_class' => XlsxReportExportRenderer::class,
                        ],
                    ],
                ],
            ],
            ReportPublicationReleaseArtifactTestFactory::verifier(),
            new ReportDefinitionSemanticFingerprint,
            new ReportPublicationDeliveryContractHasher,
            static fn (): DateTimeImmutable => $releaseAt->modify('+1 day'),
        );
        $releaseArtifact = ReportPublicationReleaseArtifactTestFactory::issue(
            $proof,
            $candidateManifestHash,
            $officialManifestHash,
            $release,
            $ciPayload,
        );
        $eligible = $eligibilityService->evaluate(
            $candidate,
            $row,
            $binding,
            $evidence,
            $proof,
            $candidateManifestHash,
            $officialManifestHash,
            $release,
            $releaseArtifact,
        )->publication();

        return [
            'candidate' => $candidate,
            'eligible' => $eligible,
            'eligibility_service' => $eligibilityService,
            'registry' => new SingleCandidatePublicationRegistry($candidate),
        ];
    }

    private static function withXlsxRenderer(
        \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition $definition,
        \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding $binding,
        \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence $evidence,
        bool $productionHashes = false,
    ): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence {
        $rendererFile = (new \ReflectionClass(XlsxReportExportRenderer::class))->getFileName();
        if (! is_string($rendererFile)) {
            throw new LogicException('report_publication_fixture_renderer_unavailable');
        }
        $components = $evidence->componentClassHashes;
        $components[XlsxReportExportRenderer::class] = new Sha256Hash((string) hash_file('sha256', $rendererFile));

        return CatalogBindingTestFactory::evidence(
            $definition,
            $binding,
            $evidence->fixtureHash,
            componentHashes: $components,
            productionHashes: $productionHashes,
        );
    }

    private static function binding(ReportDefinition $definition, bool $productionComponents): ReportDefinitionBinding
    {
        if (! $productionComponents) {
            return CatalogBindingTestFactory::binding($definition);
        }
        $adapter = (new ReflectionClass(PlanFactReportSourceSnapshotAdapter::class))->newInstanceWithoutConstructor();

        return new ReportDefinitionBinding(
            $definition->code,
            $definition->definitionHash,
            $definition->contractVersion,
            $adapter,
            $adapter,
            $adapter,
            null,
        );
    }

    private static function requiredChecks(): array
    {
        return [
            'binding_contract',
            'drill_down_contract',
            'export_xlsx_contract',
            'formula_contract',
            'postgresql_contract',
            'rbac_contract',
            'source_contract',
        ];
    }
}

final readonly class SingleCandidatePublicationRegistry implements CandidateReportDefinitionRegistry
{
    public function __construct(private CandidateReportDefinition $candidate) {}

    public function candidate(string $code): CandidateReportDefinition
    {
        if (! hash_equals($code, $this->candidate->code)) {
            throw new LogicException('report_publication_fixture_candidate_not_found');
        }

        return $this->candidate;
    }

    public function candidateCodes(): array
    {
        return [$this->candidate->code];
    }
}
