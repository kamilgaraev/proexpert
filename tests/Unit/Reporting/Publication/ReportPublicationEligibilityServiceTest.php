<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Catalog\ReportPermissionCatalog;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportDefinitionSemanticFingerprint;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportDefinitionVersionPolicy;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationAdmissionProfile;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationAdmissionProfileCatalog;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationBindingHasher;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationDeliveryContractHasher;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationEligibilityService;
use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationIdentity;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationRecord;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseIdentity;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Reporting\CatalogBindingTestFactory;
use Tests\Support\Reporting\CatalogTestDataProvider;
use Tests\Support\Reporting\Publication\ReportPublicationFixtureFactory;
use Tests\Support\Reporting\Publication\ReportPublicationReleaseArtifactTestFactory;

final class ReportPublicationEligibilityServiceTest extends TestCase
{
    public function test_self_declared_unsigned_ci_document_is_not_release_authority(): void
    {
        $scenario = $this->scenario();
        $artifact = json_decode($scenario['ci_artifact'], true, 512, JSON_THROW_ON_ERROR);
        $scenario['ci_artifact'] = CanonicalJson::encode($artifact['evidence']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_ineligible');

        $this->service()->evaluate(
            $scenario['candidate'],
            $scenario['document'],
            $scenario['binding'],
            $scenario['evidence'],
            $scenario['proof'],
            $scenario['candidate_manifest_hash'],
            $scenario['official_manifest_hash'],
            $scenario['release'],
            $scenario['ci_artifact'],
        );
    }

    public function test_candidate_without_an_explicit_admission_profile_is_rejected(): void
    {
        $scenario = $this->scenario();
        $profiles = new ReportPublicationAdmissionProfileCatalog([
            new ReportPublicationAdmissionProfile(
                'procurement_cycle',
                self::requiredChecks(),
                str_repeat('2', 64),
                [
                    'xlsx' => [
                        'schema_sha256' => str_repeat('e', 64),
                        'renderer_class' => XlsxReportExportRenderer::class,
                    ],
                ],
            ),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_ineligible');

        $this->service(XlsxReportExportRenderer::class, $profiles)->evaluate(
            $scenario['candidate'],
            $scenario['document'],
            $scenario['binding'],
            $scenario['evidence'],
            $scenario['proof'],
            $scenario['candidate_manifest_hash'],
            $scenario['official_manifest_hash'],
            $scenario['release'],
            $scenario['ci_artifact'],
        );
    }

    public function test_candidate_with_a_profile_for_another_export_set_is_rejected_before_eligibility_checks(): void
    {
        $scenario = $this->scenario();
        $profiles = new ReportPublicationAdmissionProfileCatalog([
            new ReportPublicationAdmissionProfile(
                'project_portfolio_health',
                ['binding_contract', 'drill_down_contract', 'export_csv_contract', 'formula_contract', 'rbac_contract', 'source_contract'],
                str_repeat('2', 64),
                [
                    'csv' => [
                        'schema_sha256' => str_repeat('e', 64),
                        'renderer_class' => \App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer::class,
                    ],
                ],
            ),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_ineligible');

        $this->service(XlsxReportExportRenderer::class, $profiles)->evaluate(
            $scenario['candidate'],
            $scenario['document'],
            $scenario['binding'],
            $scenario['evidence'],
            $scenario['proof'],
            $scenario['candidate_manifest_hash'],
            $scenario['official_manifest_hash'],
            $scenario['release'],
            $scenario['ci_artifact'],
        );
    }

    public function test_data_provider_cannot_claim_the_xlsx_renderer_contract(): void
    {
        $scenario = $this->scenario();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_admission_profile_invalid');

        $this->service(CatalogTestDataProvider::class)->evaluate(
            $scenario['candidate'],
            $scenario['document'],
            $scenario['binding'],
            $scenario['evidence'],
            $scenario['proof'],
            $scenario['candidate_manifest_hash'],
            $scenario['official_manifest_hash'],
            $scenario['release'],
            $scenario['ci_artifact'],
        );
    }

    public function test_release_timestamp_in_the_future_fails_closed(): void
    {
        $scenario = $this->scenario();
        $payload = $scenario['proof']->payload();
        $payload['release']['created_at_utc'] = '2099-08-01T02:03:04.654321Z';
        $scenario['proof'] = ReportPublicationProof::fromArray($payload);
        $scenario['release'] = new ReportPublicationReleaseIdentity(
            $scenario['release']->gitSha,
            new DateTimeImmutable('2099-08-01T02:03:04.654321+00:00'),
            $scenario['release']->approverIdentity,
        );
        $artifact = json_decode($scenario['ci_artifact'], true, 512, JSON_THROW_ON_ERROR);
        $scenario['ci_artifact'] = ReportPublicationReleaseArtifactTestFactory::issue(
            $scenario['proof'],
            $scenario['candidate_manifest_hash'],
            $scenario['official_manifest_hash'],
            $scenario['release'],
            $artifact['evidence'],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_ineligible');

        $this->service()->evaluate(
            $scenario['candidate'],
            $scenario['document'],
            $scenario['binding'],
            $scenario['evidence'],
            $scenario['proof'],
            $scenario['candidate_manifest_hash'],
            $scenario['official_manifest_hash'],
            $scenario['release'],
            $scenario['ci_artifact'],
        );
    }

    public function test_persistence_fixture_is_eligible_through_the_same_gate(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible();

        self::assertSame(
            $fixture['candidate']->code,
            $fixture['eligible']->candidate->code,
        );
        self::assertSame(
            $fixture['eligible']->proof->digest()->value,
            $fixture['eligible']->proofHash->value,
        );
    }

    public function test_exact_candidate_binding_evidence_ci_and_release_become_eligible(): void
    {
        $scenario = $this->scenario();

        $result = $this->service()->evaluate(
            $scenario['candidate'],
            $scenario['document'],
            $scenario['binding'],
            $scenario['evidence'],
            $scenario['proof'],
            $scenario['candidate_manifest_hash'],
            $scenario['official_manifest_hash'],
            $scenario['release'],
            $scenario['ci_artifact'],
        );

        self::assertTrue($result->eligible());
        self::assertSame($scenario['proof']->digest()->value, $result->publication()->proofHash->value);
        self::assertSame('project_portfolio_health', $result->publication()->candidate->code);
        self::assertSame($scenario['official_manifest_hash']->value, $result->publication()->officialManifestHash->value);
        self::assertSame($scenario['binding'], $result->publication()->binding);
        self::assertSame($scenario['evidence'], $result->publication()->evidence);
        self::assertSame($scenario['ci_artifact'], $result->publication()->releaseArtifactBytes);
    }

    public function test_later_release_can_reuse_evidence_only_from_the_exact_previous_publication(): void
    {
        $scenario = $this->subsequentReleaseScenario();

        $result = $this->service()->evaluate(
            $scenario['candidate'],
            $scenario['document'],
            $scenario['binding'],
            $scenario['evidence'],
            $scenario['proof'],
            $scenario['candidate_manifest_hash'],
            $scenario['official_manifest_hash'],
            $scenario['release'],
            $scenario['ci_artifact'],
            $scenario['previous'],
        );

        self::assertTrue($result->eligible());
        self::assertSame(str_repeat('b', 40), $result->publication()->release->gitSha);
    }

    public function test_later_release_without_exact_previous_publication_cannot_reuse_stale_evidence(): void
    {
        $scenario = $this->subsequentReleaseScenario();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_ineligible');

        $this->service()->evaluate(
            $scenario['candidate'],
            $scenario['document'],
            $scenario['binding'],
            $scenario['evidence'],
            $scenario['proof'],
            $scenario['candidate_manifest_hash'],
            $scenario['official_manifest_hash'],
            $scenario['release'],
            $scenario['ci_artifact'],
        );
    }

    public function test_later_release_must_be_newer_than_the_previous_disable(): void
    {
        $scenario = $this->subsequentReleaseScenario();
        $payload = $scenario['proof']->payload();
        $ciPayload = [
            'checks' => array_fill_keys(self::requiredChecks(), 'passed'),
            'commit_sha' => str_repeat('b', 40),
            'completed_at_utc' => '2026-08-01T02:00:00.000000Z',
            'run_id' => 'ci-2002',
        ];
        $scenario['ci_artifact'] = CanonicalJson::encode($ciPayload);
        $payload['ci']['completed_at_utc'] = $ciPayload['completed_at_utc'];
        $payload['ci']['suite_sha256'] = hash('sha256', $scenario['ci_artifact']);
        $payload['release']['created_at_utc'] = '2026-08-01T02:15:00.000000Z';
        $scenario['proof'] = ReportPublicationProof::fromArray($payload);
        $scenario['release'] = new ReportPublicationReleaseIdentity(
            str_repeat('b', 40),
            new DateTimeImmutable('2026-08-01T02:15:00.000000+00:00'),
            'release-bot@most',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_ineligible');

        $this->service()->evaluate(
            $scenario['candidate'],
            $scenario['document'],
            $scenario['binding'],
            $scenario['evidence'],
            $scenario['proof'],
            $scenario['candidate_manifest_hash'],
            $scenario['official_manifest_hash'],
            $scenario['release'],
            $scenario['ci_artifact'],
            $scenario['previous'],
        );
    }

    public function test_disabled_publication_cannot_be_replayed_with_the_same_proof(): void
    {
        $scenario = $this->scenario();
        $previous = $this->subsequentReleaseScenario()['previous'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_ineligible');

        $this->service()->evaluate(
            $scenario['candidate'],
            $scenario['document'],
            $scenario['binding'],
            $scenario['evidence'],
            $scenario['proof'],
            $scenario['candidate_manifest_hash'],
            $scenario['official_manifest_hash'],
            $scenario['release'],
            $scenario['ci_artifact'],
            $previous,
        );
    }

    #[DataProvider('mismatchProvider')]
    public function test_any_unsealed_contract_mismatch_fails_closed(callable $mutate): void
    {
        $scenario = $this->scenario();
        $mutate($scenario);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_ineligible');

        $this->service()->evaluate(
            $scenario['candidate'],
            $scenario['document'],
            $scenario['binding'],
            $scenario['evidence'],
            $scenario['proof'],
            $scenario['candidate_manifest_hash'],
            $scenario['official_manifest_hash'],
            $scenario['release'],
            $scenario['ci_artifact'],
        );
    }

    public static function mismatchProvider(): iterable
    {
        yield 'candidate document changed after hashing' => [static function (array &$scenario): void {
            $scenario['document']['columns'][0]['id'] = 'different_column';
        }];
        yield 'binding implementation hash changed' => [static function (array &$scenario): void {
            $payload = $scenario['proof']->payload();
            $payload['binding_sha256'] = str_repeat('f', 64);
            $scenario['proof'] = ReportPublicationProof::fromArray($payload);
        }];
        yield 'source evidence changed' => [static function (array &$scenario): void {
            $payload = $scenario['proof']->payload();
            $payload['source']['rows_sha256'] = str_repeat('f', 64);
            $scenario['proof'] = ReportPublicationProof::fromArray($payload);
        }];
        yield 'proof semantic fingerprint differs from the candidate and evidence' => [static function (array &$scenario): void {
            $payload = $scenario['proof']->payload();
            $payload['semantic_fingerprints']['source'] = str_repeat('f', 64);
            $scenario['proof'] = ReportPublicationProof::fromArray($payload);
        }];
        yield 'run permission differs from definition' => [static function (array &$scenario): void {
            $payload = $scenario['proof']->payload();
            $payload['permissions']['run'] = ['budgeting.portfolio_dashboard.export'];
            $scenario['proof'] = ReportPublicationProof::fromArray($payload);
        }];
        yield 'export proof has an extra format' => [static function (array &$scenario): void {
            $payload = $scenario['proof']->payload();
            $extra = $payload['export_contracts'][0];
            $extra['format'] = 'pdf';
            $extra['assertion_codes'] = [
                'export.pdf.fixture.passed',
                'export.pdf.provenance.passed',
                'export.pdf.redaction.passed',
                'export.pdf.renderer.passed',
                'export.pdf.schema.passed',
            ];
            array_unshift($payload['export_contracts'], $extra);
            $scenario['proof'] = ReportPublicationProof::fromArray($payload);
        }];
        yield 'CI omits required PostgreSQL contract' => [static function (array &$scenario): void {
            $payload = $scenario['proof']->payload();
            $payload['ci']['required_checks'] = array_values(array_filter(
                $payload['ci']['required_checks'],
                static fn (string $check): bool => $check !== 'postgresql_contract',
            ));
            $scenario['proof'] = ReportPublicationProof::fromArray($payload);
        }];
        yield 'CI artifact bytes do not match suite hash' => [static function (array &$scenario): void {
            $scenario['ci_artifact'] .= "\n";
        }];
        yield 'drill-down schema is not the reviewed contract' => [static function (array &$scenario): void {
            $payload = $scenario['proof']->payload();
            $payload['drill_down_contract']['schema_sha256'] = str_repeat('f', 64);
            $scenario['proof'] = ReportPublicationProof::fromArray($payload);
        }];
        yield 'release commit differs from evidence' => [static function (array &$scenario): void {
            $scenario['release'] = new ReportPublicationReleaseIdentity(
                str_repeat('b', 40),
                new DateTimeImmutable('2026-08-01T02:03:04.654321+00:00'),
                'release-bot@most',
            );
        }];
    }

    private function scenario(): array
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
        $temporaryEvidence = $this->withXlsxRenderer($temporary, $temporaryBinding, $temporaryEvidence);
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
        $evidence = $this->withXlsxRenderer($definition, $binding, $evidence);
        $candidateManifestHash = new Sha256Hash(str_repeat('1', 64));
        $officialManifestHash = new Sha256Hash(str_repeat('0', 64));
        $requiredChecks = self::requiredChecks();
        $ciPayload = [
            'run_id' => 'ci-1001',
            'commit_sha' => $evidence->commitSha,
            'completed_at_utc' => '2026-08-01T01:02:03.123456Z',
            'checks' => array_fill_keys($requiredChecks, 'passed'),
        ];
        $ciEvidenceBytes = CanonicalJson::encode($ciPayload);

        $components = [];
        foreach ($evidence->componentClassHashes as $class => $hash) {
            $components[] = ['class' => $class, 'sha256' => $hash->value];
        }
        $bindingHash = (new ReportPublicationBindingHasher)->hash($binding, $evidence);
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
            new Sha256Hash(str_repeat('e', 64)),
            $evidence->fixtureHash,
            $rendererAssertions,
        );
        $proof = ReportPublicationProof::fromArray([
            'code' => $definition->code,
            'candidate_manifest_sha256' => $candidateManifestHash->value,
            'candidate_definition_sha256' => $definition->definitionHash->value,
            'binding_sha256' => $bindingHash->value,
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
                'schema_sha256' => str_repeat('e', 64),
                'fixture_sha256' => $evidence->fixtureHash->value,
                'renderer_class' => XlsxReportExportRenderer::class,
                'renderer_contract_sha256' => $rendererContractHash->value,
                'renderer_sha256' => $rendererHash->value,
                'assertion_codes' => $rendererAssertions,
            ]],
            'drill_down_contract' => [
                'schema_sha256' => str_repeat('2', 64),
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
                'git_sha' => $evidence->commitSha,
                'created_at_utc' => '2026-08-01T02:03:04.654321Z',
                'approver_identity' => 'release-bot@most',
            ],
        ]);

        $release = new ReportPublicationReleaseIdentity(
            $evidence->commitSha,
            new DateTimeImmutable('2026-08-01T02:03:04.654321+00:00'),
            'release-bot@most',
        );
        $releaseArtifact = ReportPublicationReleaseArtifactTestFactory::issue(
            $proof,
            $candidateManifestHash,
            $officialManifestHash,
            $release,
            $ciPayload,
        );

        return [
            'candidate' => new CandidateReportDefinition($definition),
            'document' => $row,
            'binding' => $binding,
            'evidence' => $evidence,
            'proof' => $proof,
            'candidate_manifest_hash' => $candidateManifestHash,
            'official_manifest_hash' => $officialManifestHash,
            'release' => $release,
            'ci_artifact' => $releaseArtifact,
        ];
    }

    private function service(
        string $rendererClass = XlsxReportExportRenderer::class,
        ?ReportPublicationAdmissionProfileCatalog $admissionProfiles = null,
    ): ReportPublicationEligibilityService {
        return new ReportPublicationEligibilityService(
            new ReportPermissionCatalog,
            new ReportDefinitionVersionPolicy,
            new ReportPublicationBindingHasher,
            $admissionProfiles ?? new ReportPublicationAdmissionProfileCatalog([
                new ReportPublicationAdmissionProfile(
                    'project_portfolio_health',
                    self::requiredChecks(),
                    str_repeat('2', 64),
                    [
                        'xlsx' => [
                            'schema_sha256' => str_repeat('e', 64),
                            'renderer_class' => $rendererClass,
                        ],
                    ],
                ),
            ]),
            ReportPublicationReleaseArtifactTestFactory::verifier(),
            new ReportDefinitionSemanticFingerprint,
            new ReportPublicationDeliveryContractHasher,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-08-02T00:00:00.000000+00:00'),
        );
    }

    private function subsequentReleaseScenario(): array
    {
        $scenario = $this->scenario();
        $previous = new ReportPublicationRecord(
            new ReportPublicationIdentity(
                '01J00000000000000000000000',
                $scenario['candidate']->code,
                $scenario['proof']->digest(),
                $scenario['release']->gitSha,
            ),
            ReportPublicationStatus::DISABLED,
            $scenario['proof'],
            $scenario['document'],
            new DateTimeImmutable('2026-08-01T02:03:04.654321+00:00'),
            new DateTimeImmutable('2026-08-01T02:30:00.000000+00:00'),
            'release_replaced',
        );
        $payload = $scenario['proof']->payload();
        $ciPayload = [
            'checks' => array_fill_keys(self::requiredChecks(), 'passed'),
            'commit_sha' => str_repeat('b', 40),
            'completed_at_utc' => '2026-08-01T03:00:00.000000Z',
            'run_id' => 'ci-2002',
        ];
        $ciEvidenceBytes = CanonicalJson::encode($ciPayload);
        $payload['ci'] = [
            'run_id' => $ciPayload['run_id'],
            'commit_sha' => $ciPayload['commit_sha'],
            'suite_sha256' => hash('sha256', $ciEvidenceBytes),
            'completed_at_utc' => $ciPayload['completed_at_utc'],
            'required_checks' => self::requiredChecks(),
        ];
        $payload['release'] = [
            'git_sha' => str_repeat('b', 40),
            'created_at_utc' => '2026-08-01T04:00:00.000000Z',
            'approver_identity' => 'release-bot@most',
        ];
        $scenario['proof'] = ReportPublicationProof::fromArray($payload);
        $scenario['release'] = new ReportPublicationReleaseIdentity(
            str_repeat('b', 40),
            new DateTimeImmutable('2026-08-01T04:00:00.000000+00:00'),
            'release-bot@most',
        );
        $scenario['ci_artifact'] = ReportPublicationReleaseArtifactTestFactory::issue(
            $scenario['proof'],
            $scenario['candidate_manifest_hash'],
            $scenario['official_manifest_hash'],
            $scenario['release'],
            $ciPayload,
        );
        $scenario['previous'] = $previous;

        return $scenario;
    }

    private function withXlsxRenderer(
        \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition $definition,
        \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding $binding,
        \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence $evidence,
    ): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence {
        $rendererFile = (new \ReflectionClass(XlsxReportExportRenderer::class))->getFileName();
        self::assertIsString($rendererFile);
        $components = $evidence->componentClassHashes;
        $components[XlsxReportExportRenderer::class] = new Sha256Hash((string) hash_file('sha256', $rendererFile));

        return CatalogBindingTestFactory::evidence(
            $definition,
            $binding,
            $evidence->fixtureHash,
            componentHashes: $components,
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
