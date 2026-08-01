<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Application\Catalog\ReportPermissionCatalog;
use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\EligibleReportPublication;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationEligibilityResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationRecord;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseIdentity;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class ReportPublicationEligibilityService
{
    public function __construct(
        private readonly ReportPermissionCatalog $permissions,
        private readonly ReportDefinitionVersionPolicy $versions,
        private readonly ReportPublicationBindingHasher $bindings,
        private readonly array $requiredChecksByCode,
        private readonly array $deliveryContractsByCode,
        private readonly ReportDefinitionSemanticFingerprint $fingerprints = new ReportDefinitionSemanticFingerprint,
    ) {}

    public function evaluate(
        CandidateReportDefinition $candidate,
        array $candidateDocument,
        ReportDefinitionBinding $binding,
        ReportDefinitionConformanceEvidence $evidence,
        ReportPublicationProof $proof,
        Sha256Hash $candidateManifestHash,
        Sha256Hash $officialManifestHash,
        ReportPublicationReleaseIdentity $release,
        string $ciArtifactBytes,
        ?ReportPublicationRecord $previous = null,
    ): ReportPublicationEligibilityResult {
        try {
            $this->assertEligible(
                $candidate,
                $candidateDocument,
                $binding,
                $evidence,
                $proof,
                $candidateManifestHash,
                $release,
                $ciArtifactBytes,
                $previous,
            );

            return new ReportPublicationEligibilityResult(new EligibleReportPublication(
                $candidate,
                $candidateDocument,
                $binding,
                $evidence,
                $proof,
                $proof->digest(),
                $candidateManifestHash,
                $officialManifestHash,
                $release,
                $ciArtifactBytes,
            ));
        } catch (Throwable $exception) {
            if ($exception instanceof InvalidArgumentException
                && $exception->getMessage() === 'report_publication_ineligible') {
                throw $exception;
            }

            throw new InvalidArgumentException('report_publication_ineligible', 0, $exception);
        }
    }

    private function assertEligible(
        CandidateReportDefinition $candidate,
        array $candidateDocument,
        ReportDefinitionBinding $binding,
        ReportDefinitionConformanceEvidence $evidence,
        ReportPublicationProof $proof,
        Sha256Hash $candidateManifestHash,
        ReportPublicationReleaseIdentity $release,
        string $ciArtifactBytes,
        ?ReportPublicationRecord $previous,
    ): void {
        $payload = $proof->payload();
        if (! hash_equals($candidate->code, $payload['code'])
            || ! hash_equals($candidate->definitionHash->value, $payload['candidate_definition_sha256'])
            || ! hash_equals($candidateManifestHash->value, $payload['candidate_manifest_sha256'])
            || ! hash_equals(
                $candidate->definitionHash->value,
                hash('sha256', CanonicalJson::encode($candidateDocument)),
            )
            || ($candidateDocument['code'] ?? null) !== $candidate->code
            || ! $this->readinessIsCandidate($candidateDocument)) {
            $this->ineligible();
        }

        if (! hash_equals($binding->code, $candidate->code)
            || ! hash_equals($binding->definitionHash->value, $candidate->definitionHash->value)
            || ! hash_equals($binding->contractVersion, $candidate->definition->contractVersion)
            || ! hash_equals($payload['binding_sha256'], $this->bindings->hash($binding, $evidence)->value)) {
            $this->ineligible();
        }

        if (! $evidence->passed()
            || ! hash_equals($evidence->code, $candidate->code)
            || ! hash_equals($evidence->definitionHash->value, $candidate->definitionHash->value)
            || ! hash_equals($evidence->contractVersion, $candidate->definition->contractVersion)
            || ! hash_equals($evidence->digest()->value, $payload['conformance_evidence_sha256'])
            || ! hash_equals($evidence->fixtureHash->value, $payload['fixture_sha256'])) {
            $this->ineligible();
        }

        if ($previous === null) {
            $this->versions->assertInitial($candidateDocument, $evidence);
        } else {
            if (! hash_equals($previous->identity->code, $candidate->code)) {
                $this->ineligible();
            }
            $this->versions->assertAllowed($previous->candidateDocument, $candidateDocument, $evidence);
        }
        $this->assertVersions($candidate, $payload);
        $this->assertSemanticFingerprints($candidateDocument, $evidence, $payload);
        $this->assertEvidence($evidence, $payload);
        $this->assertComponents($evidence, $payload);
        $this->assertPermissions($candidate, $payload);
        $this->assertDeliveryContracts($candidate, $evidence, $payload);
        $this->assertReleaseSequence($proof, $release, $previous);
        $this->assertCi($candidate->code, $evidence, $payload, $release, $ciArtifactBytes, $previous);
    }

    private function assertReleaseSequence(
        ReportPublicationProof $proof,
        ReportPublicationReleaseIdentity $release,
        ?ReportPublicationRecord $previous,
    ): void {
        if ($previous === null) {
            return;
        }
        $activeIdempotentReplay = $previous->status === ReportPublicationStatus::PUBLISHED
            && hash_equals($previous->identity->proofHash->value, $proof->digest()->value);
        $previousBoundary = $previous->disabledAt ?? $previous->publishedAt;
        if (! $activeIdempotentReplay && $release->createdAt <= $previousBoundary) {
            $this->ineligible();
        }
    }

    private function assertVersions(CandidateReportDefinition $candidate, array $payload): void
    {
        $definition = $candidate->definition;
        $expected = [
            'source_schema' => $definition->sourceSchemaVersion,
            'formula' => $definition->formulaVersion,
            'contract' => $definition->contractVersion,
            'renderer' => $definition->rendererVersion,
        ];
        if ($payload['versions'] !== $expected
            || ! hash_equals($payload['contract_version'], $definition->contractVersion)) {
            $this->ineligible();
        }
    }

    private function assertSemanticFingerprints(
        array $candidateDocument,
        ReportDefinitionConformanceEvidence $evidence,
        array $payload,
    ): void {
        $candidateFingerprints = $candidateDocument['semantic_fingerprints'] ?? null;
        $expected = [
            'source' => $this->fingerprints->source($candidateDocument, $evidence),
            'formula' => $this->fingerprints->formula($evidence),
        ];
        if (! is_array($candidateFingerprints)
            || ($candidateFingerprints['source'] ?? null) !== $expected['source']
            || ($candidateFingerprints['formula'] ?? null) !== $expected['formula']
            || $payload['semantic_fingerprints'] !== $expected) {
            $this->ineligible();
        }
    }

    private function assertEvidence(ReportDefinitionConformanceEvidence $evidence, array $payload): void
    {
        if ($payload['source'] !== [
            'snapshot_kind' => $evidence->source->snapshotKind,
            'snapshot_id' => $evidence->source->snapshotId,
            'source_sha256' => $evidence->source->sourceHash->value,
            'rows_sha256' => $evidence->source->rowsHash->value,
            'row_count' => $evidence->source->rowCount,
            'assertion_codes' => $evidence->source->assertionCodes,
        ] || $payload['formula'] !== [
            'formula_version' => $evidence->formula->formulaVersion,
            'totals_sha256' => $evidence->formula->totalsHash->value,
            'assertion_codes' => $evidence->formula->assertionCodes,
        ]) {
            $this->ineligible();
        }
    }

    private function assertComponents(ReportDefinitionConformanceEvidence $evidence, array $payload): void
    {
        $expected = [];
        foreach ($evidence->componentClassHashes as $class => $hash) {
            $expected[] = ['class' => $class, 'sha256' => $hash->value];
        }
        if ($payload['components'] !== $expected) {
            $this->ineligible();
        }
    }

    private function assertPermissions(CandidateReportDefinition $candidate, array $payload): void
    {
        $policy = $candidate->definition->permissionPolicy;
        $expected = [
            'view' => $policy->viewPermissions,
            'run' => $policy->viewPermissions,
            'export' => $policy->exportPermissions,
            'download' => $policy->exportPermissions,
            'sensitive' => $policy->sensitivePermissions,
            'audit' => $policy->auditPermissions,
        ];
        if ($payload['permissions'] !== $expected) {
            $this->ineligible();
        }
        foreach ($expected as $permissions) {
            $this->permissions->assertKnownAndTranslated($permissions);
        }
    }

    private function assertDeliveryContracts(
        CandidateReportDefinition $candidate,
        ReportDefinitionConformanceEvidence $evidence,
        array $payload,
    ): void {
        $delivery = $this->deliveryContractsByCode[$candidate->code] ?? null;
        if (! is_array($delivery)
            || array_keys($delivery) !== ['drill_down_schema_sha256', 'exports']
            || ! is_array($delivery['exports'])) {
            $this->ineligible();
        }
        $formats = $candidate->definition->formats;
        sort($formats, SORT_STRING);
        if ($payload['export_contracts'] === []
            || array_column($payload['export_contracts'], 'format') !== $formats
            || ! hash_equals(
                (string) $delivery['drill_down_schema_sha256'],
                $payload['drill_down_contract']['schema_sha256'],
            )
            || ! in_array('drill_down.schema.passed', $payload['drill_down_contract']['assertion_codes'], true)) {
            $this->ineligible();
        }
        foreach ($payload['export_contracts'] as $contract) {
            $format = $contract['format'];
            $expectedContract = $delivery['exports'][$format] ?? null;
            $rendererClass = is_array($expectedContract) ? ($expectedContract['renderer_class'] ?? null) : null;
            $rendererHash = is_string($rendererClass)
                ? ($evidence->componentClassHashes[$rendererClass] ?? null)
                : null;
            $requiredAssertions = [
                "export.{$format}.fixture.passed",
                "export.{$format}.provenance.passed",
                "export.{$format}.redaction.passed",
                "export.{$format}.renderer.passed",
                "export.{$format}.schema.passed",
            ];
            if (! is_array($expectedContract)
                || array_keys($expectedContract) !== ['schema_sha256', 'renderer_class']
                || ! $rendererHash instanceof \App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash
                || $contract['assertion_codes'] !== $requiredAssertions
                || ! hash_equals($contract['fixture_sha256'], $evidence->fixtureHash->value)
                || ! hash_equals((string) $expectedContract['schema_sha256'], $contract['schema_sha256'])
                || ! hash_equals($rendererHash->value, $contract['renderer_sha256'])) {
                $this->ineligible();
            }
        }
    }

    private function assertCi(
        string $code,
        ReportDefinitionConformanceEvidence $evidence,
        array $payload,
        ReportPublicationReleaseIdentity $release,
        string $artifactBytes,
        ?ReportPublicationRecord $previous,
    ): void {
        $requiredChecks = $this->requiredChecks($code, $payload);
        if ($requiredChecks === []
            || $payload['ci']['required_checks'] !== $requiredChecks
            || ! hash_equals(hash('sha256', $artifactBytes), $payload['ci']['suite_sha256'])) {
            $this->ineligible();
        }

        try {
            $artifact = json_decode($artifactBytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->ineligible();
        }
        if (! is_array($artifact)
            || CanonicalJson::encode($artifact) !== $artifactBytes
            || array_keys($artifact) !== ['checks', 'commit_sha', 'completed_at_utc', 'run_id']
            || ! is_array($artifact['checks'])
            || array_keys($artifact['checks']) !== $requiredChecks
            || array_unique(array_values($artifact['checks'])) !== ['passed']
            || ! hash_equals($artifact['run_id'], $payload['ci']['run_id'])
            || ! hash_equals($artifact['commit_sha'], $payload['ci']['commit_sha'])
            || ! hash_equals($artifact['completed_at_utc'], $payload['ci']['completed_at_utc'])
            || ! hash_equals($release->gitSha, $payload['ci']['commit_sha'])
            || ! hash_equals($release->gitSha, $payload['release']['git_sha'])
            || ! hash_equals($release->createdAtUtc(), $payload['release']['created_at_utc'])
            || ! hash_equals($release->approverIdentity, $payload['release']['approver_identity'])) {
            $this->ineligible();
        }

        if ($previous === null) {
            if (! hash_equals($evidence->commitSha, $payload['ci']['commit_sha'])) {
                $this->ineligible();
            }
        } elseif (! hash_equals($evidence->commitSha, $payload['ci']['commit_sha'])) {
            $this->assertEvidenceReuse($previous, $payload);
        }

        $completedAt = new DateTimeImmutable($payload['ci']['completed_at_utc']);
        if ($completedAt > $release->createdAt || $evidence->generatedAt > $completedAt) {
            $this->ineligible();
        }
    }

    private function requiredChecks(string $code, array $payload): array
    {
        $configured = $this->requiredChecksByCode[$code] ?? null;
        if (! is_array($configured) || ! array_is_list($configured)) {
            $this->ineligible();
        }
        $required = [
            'binding_contract',
            'drill_down_contract',
            'formula_contract',
            'rbac_contract',
            'source_contract',
        ];
        foreach (array_column($payload['export_contracts'], 'format') as $format) {
            $required[] = 'export_'.$format.'_contract';
        }
        $allowed = [...$required, 'postgresql_contract'];
        sort($required, SORT_STRING);
        $seen = [];
        foreach ($configured as $check) {
            if (! is_string($check) || ! in_array($check, $allowed, true) || isset($seen[$check])) {
                $this->ineligible();
            }
            $seen[$check] = true;
        }
        $normalized = array_keys($seen);
        $sorted = $normalized;
        sort($sorted, SORT_STRING);
        if ($normalized !== $sorted || array_diff($required, $normalized) !== []) {
            $this->ineligible();
        }

        return $normalized;
    }

    private function assertEvidenceReuse(ReportPublicationRecord $previous, array $payload): void
    {
        $previousPayload = $previous->proof->payload();
        foreach ([
            'code',
            'candidate_manifest_sha256',
            'candidate_definition_sha256',
            'binding_sha256',
            'contract_version',
            'versions',
            'semantic_fingerprints',
            'fixture_sha256',
            'conformance_evidence_sha256',
            'source',
            'formula',
            'components',
            'permissions',
            'export_contracts',
            'drill_down_contract',
        ] as $field) {
            if (! $this->sameSealedValue(
                $previousPayload[$field] ?? null,
                $payload[$field] ?? null,
            )) {
                $this->ineligible();
            }
        }
    }

    private function sameSealedValue(mixed $previous, mixed $candidate): bool
    {
        if (is_string($previous) && is_string($candidate)) {
            return hash_equals($previous, $candidate);
        }
        if (is_array($previous) && is_array($candidate)) {
            return hash_equals(
                CanonicalJson::encode($previous),
                CanonicalJson::encode($candidate),
            );
        }

        return $previous === $candidate;
    }

    private function readinessIsCandidate(array $document): bool
    {
        $readiness = $document['readiness'] ?? null;

        return is_array($readiness)
            && ! array_is_list($readiness)
            && $readiness === [
                'source' => 'ready',
                'formula' => 'ready',
                'delivery' => 'verified',
                'publication' => 'candidate',
            ];
    }

    private function ineligible(): never
    {
        throw new InvalidArgumentException('report_publication_ineligible');
    }
}
