<?php

declare(strict_types=1);

namespace Tests\Support\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Catalog\ReportPermissionCatalog;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportDefinitionSemanticFingerprint;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportDefinitionVersionPolicy;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationBindingHasher;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationEligibilityService;
use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseIdentity;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Reporting\CatalogBindingTestFactory;
use Tests\Support\Reporting\CatalogTestDataProvider;

final class ReportPublicationFixtureFactory
{
    public static function eligible(string $exportSchemaDigit = 'e'): array
    {
        $document = Yaml::parseFile(dirname(__DIR__, 4).'/tests/Fixtures/Reporting/Publication/candidate.valid.yaml');
        $row = $document['definitions'][0];
        $factory = new ReportDefinitionFactory;
        $temporary = $factory->fromManifest($row);
        $temporaryBinding = CatalogBindingTestFactory::binding($temporary);
        $temporaryEvidence = CatalogBindingTestFactory::evidence(
            $temporary,
            $temporaryBinding,
            new Sha256Hash(str_repeat('6', 64)),
        );
        $fingerprints = new ReportDefinitionSemanticFingerprint;
        $row['semantic_fingerprints'] = [
            'formula' => $fingerprints->formula($temporaryEvidence),
            'source' => $fingerprints->source($row, $temporaryEvidence),
        ];
        $definition = $factory->fromManifest($row);
        $binding = CatalogBindingTestFactory::binding($definition);
        $evidence = CatalogBindingTestFactory::evidence(
            $definition,
            $binding,
            new Sha256Hash(str_repeat('6', 64)),
        );
        $requiredChecks = self::requiredChecks();
        $ciPayload = [
            'checks' => array_fill_keys($requiredChecks, 'passed'),
            'commit_sha' => $evidence->commitSha,
            'completed_at_utc' => '2026-08-01T01:02:03.123456Z',
            'run_id' => 'ci-1001',
        ];
        $ciArtifact = CanonicalJson::encode($ciPayload);
        $components = [];
        foreach ($evidence->componentClassHashes as $class => $hash) {
            $components[] = ['class' => $class, 'sha256' => $hash->value];
        }
        $candidateManifestHash = new Sha256Hash(str_repeat('1', 64));
        $officialManifestHash = new Sha256Hash(str_repeat('0', 64));
        $release = new ReportPublicationReleaseIdentity(
            $evidence->commitSha,
            new DateTimeImmutable('2026-08-01T02:03:04.654321+00:00'),
            'release-bot@most',
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
                'schema_sha256' => str_repeat($exportSchemaDigit, 64),
                'fixture_sha256' => $evidence->fixtureHash->value,
                'renderer_sha256' => $components[0]['sha256'],
                'assertion_codes' => [
                    'export.xlsx.fixture.passed',
                    'export.xlsx.provenance.passed',
                    'export.xlsx.redaction.passed',
                    'export.xlsx.renderer.passed',
                    'export.xlsx.schema.passed',
                ],
            ]],
            'drill_down_contract' => [
                'schema_sha256' => str_repeat('2', 64),
                'assertion_codes' => ['drill_down.schema.passed'],
            ],
            'ci' => [
                'run_id' => $ciPayload['run_id'],
                'commit_sha' => $ciPayload['commit_sha'],
                'suite_sha256' => hash('sha256', $ciArtifact),
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
                    'drill_down_schema_sha256' => str_repeat('2', 64),
                    'exports' => [
                        'xlsx' => [
                            'schema_sha256' => str_repeat($exportSchemaDigit, 64),
                            'renderer_class' => CatalogTestDataProvider::class,
                        ],
                    ],
                ],
            ],
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
            $ciArtifact,
        )->publication();

        return [
            'candidate' => $candidate,
            'eligible' => $eligible,
            'eligibility_service' => $eligibilityService,
            'registry' => new SingleCandidatePublicationRegistry($candidate),
        ];
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
