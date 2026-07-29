<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportSnapshotSealVerifier;
use App\BusinessModules\Core\Reporting\Application\Execution\CanonicalReportSourceHashBuilder;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportSnapshotSealValidator;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportSnapshotSealVerificationInput;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotSeal;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class ReportSnapshotSealValidatorTest extends TestCase
{
    public function test_operational_identity_is_validated_without_verifier_call(): void
    {
        [$query, $snapshot, $result] = $this->fixture(false);
        $verifier = new RecordingSealVerifier();
        $hash = (new CanonicalReportSourceHashBuilder())->build($query, $snapshot, $result);
        $snapshot = $this->withSourceHash($snapshot, $hash);
        $result = $this->withSnapshot($result, $snapshot, $hash);

        (new ReportSnapshotSealValidator($verifier))->assertSealable($query, $snapshot, $result, $hash);

        self::assertSame(0, $verifier->calls);
    }

    public function test_official_identity_delegates_exact_verification_input(): void
    {
        [$query, $snapshot, $result] = $this->fixture(true);
        $verifier = new RecordingSealVerifier();
        $hash = (new CanonicalReportSourceHashBuilder())->build($query, $snapshot, $result);
        $snapshot = $this->withSourceHash($snapshot, $hash);
        $result = $this->withSnapshot($result, $snapshot, $hash);

        (new ReportSnapshotSealValidator($verifier))->assertSealable($query, $snapshot, $result, $hash);

        self::assertSame(1, $verifier->calls);
        self::assertSame($snapshot->id, $verifier->input?->snapshotId);
        self::assertSame($hash->value, $verifier->input?->calculatedSourceHash->value);
    }

    public function test_definition_or_result_identity_drift_fails_closed(): void
    {
        [$query, $snapshot, $result] = $this->fixture(false);
        $hash = (new CanonicalReportSourceHashBuilder())->build($query, $snapshot, $result);

        $this->expectException(\App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException::class);
        (new ReportSnapshotSealValidator(new RecordingSealVerifier()))->assertSealable(
            $query,
            $snapshot,
            $result,
            new Sha256Hash(str_repeat('e', 64)),
        );
    }

    public function test_each_validator_identity_equality_fails_independently(): void
    {
        [$query, $snapshot, $result] = $this->fixture(false);
        $hash = (new CanonicalReportSourceHashBuilder())->build($query, $snapshot, $result);
        $snapshot = $this->withSourceHash($snapshot, $hash);
        $result = $this->withSnapshot($result, $snapshot, $hash);
        $otherHash = new Sha256Hash(str_repeat('e', 64));
        $otherScope = new ReportScope(1, [1], [2], [], new DateTimeZone('UTC'));
        $mutations = [
            'definition_hash' => [
                $this->copySnapshot($snapshot, definitionHash: $otherHash),
                null,
                $hash,
            ],
            'scope' => [
                $this->copySnapshot($snapshot, scope: $otherScope),
                null,
                $hash,
            ],
            'formula_version' => [
                $this->copySnapshot($snapshot, formulaVersion: '2'),
                null,
                $hash,
            ],
            'snapshot_source_hash' => [
                $this->copySnapshot($snapshot, sourceHash: $otherHash),
                null,
                $hash,
            ],
            'provenance_source_hash' => [
                $snapshot,
                $this->forgeResult($result, [
                    'provenance' => new ReportProvenance(
                        $result->provenance->sourceOfTruth,
                        $result->provenance->sourceRefs,
                        $otherHash,
                        $result->provenance->externalConfirmationRole,
                    ),
                ]),
                $hash,
            ],
            'calculated_source_hash' => [
                $snapshot,
                $result,
                $otherHash,
            ],
        ];

        foreach ($mutations as $name => [$mutatedSnapshot, $mutatedResult, $calculated]) {
            $candidateResult = $mutatedResult ?? $this->withSnapshot($result, $mutatedSnapshot, $mutatedSnapshot->sourceHash);
            try {
                (new ReportSnapshotSealValidator(new RecordingSealVerifier()))->assertSealable(
                    $query,
                    $mutatedSnapshot,
                    $candidateResult,
                    $calculated,
                );
                self::fail("Mutation {$name} was accepted.");
            } catch (ReportContractException $exception) {
                self::assertSame(ReportErrorCode::REPORT_INTERNAL_ERROR, $exception->errorCode, $name);
            }
        }
    }

    public function test_complete_metadata_snapshot_and_exact_metadata_instants_are_independently_locked(): void
    {
        [$query, $snapshot, $result] = $this->fixture(false);
        $hash = (new CanonicalReportSourceHashBuilder())->build($query, $snapshot, $result);
        $snapshot = $this->withSourceHash($snapshot, $hash);
        $result = $this->withSnapshot($result, $snapshot, $hash);
        $differentSnapshot = $this->copySnapshot($snapshot, id: 'snapshot-2');
        $cases = [
            'complete_snapshot' => $this->copyResult($result, metadata: $this->forgeMetadata(
                $differentSnapshot,
                $result->metadata->rowCount,
                $snapshot->generatedAt,
                $snapshot->staleAt,
            )),
            'generated_at_microsecond' => $this->copyResult($result, metadata: $this->forgeMetadata(
                $snapshot,
                $result->metadata->rowCount,
                $snapshot->generatedAt->modify('+0.000001 seconds'),
                $snapshot->staleAt,
            )),
            'stale_at_microsecond' => $this->copyResult($result, metadata: $this->forgeMetadata(
                $snapshot,
                $result->metadata->rowCount,
                $snapshot->generatedAt,
                new DateTimeImmutable('2026-07-29T08:15:30.123457Z'),
            )),
        ];

        foreach ($cases as $name => $candidate) {
            try {
                (new ReportSnapshotSealValidator(new RecordingSealVerifier()))->assertSealable($query, $snapshot, $candidate, $hash);
                self::fail("Mutation {$name} was accepted.");
            } catch (ReportContractException $exception) {
                self::assertSame(ReportErrorCode::REPORT_INTERNAL_ERROR, $exception->errorCode, $name);
            }
        }
    }

    public function test_classification_drift_and_official_seal_payload_drift_fail_with_exact_codes(): void
    {
        [$operationalQuery, $operationalSnapshot, $operationalResult] = $this->fixture(false);
        $hash = (new CanonicalReportSourceHashBuilder())->build($operationalQuery, $operationalSnapshot, $operationalResult);
        $forgedOfficial = $this->forgeSnapshot($operationalSnapshot, [
            'sourceHash' => $hash,
            'classification' => ReportSnapshotClassification::OFFICIAL,
            'seal' => null,
        ]);
        $forgedResult = $this->withSnapshot($operationalResult, $forgedOfficial, $hash);

        try {
            (new ReportSnapshotSealValidator(new RecordingSealVerifier()))->assertSealable($operationalQuery, $forgedOfficial, $forgedResult, $hash);
            self::fail('Classification drift was accepted.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_INTERNAL_ERROR, $exception->errorCode);
        }

        [$officialQuery, $officialSnapshot, $officialResult] = $this->fixture(true);
        $officialHash = (new CanonicalReportSourceHashBuilder())->build($officialQuery, $officialSnapshot, $officialResult);
        $wrongSeal = new ReportSnapshotSeal(
            $officialSnapshot->seal->keyId,
            $officialSnapshot->seal->algorithm,
            new Sha256Hash(str_repeat('f', 64)),
            $officialSnapshot->seal->signature,
            $officialSnapshot->seal->sealedAt,
        );
        $forgedOfficial = $this->forgeSnapshot($officialSnapshot, ['sourceHash' => $officialHash, 'seal' => $wrongSeal]);
        $forgedResult = $this->withSnapshot($officialResult, $forgedOfficial, $officialHash);

        try {
            (new ReportSnapshotSealValidator(new RecordingSealVerifier()))->assertSealable($officialQuery, $forgedOfficial, $forgedResult, $officialHash);
            self::fail('Seal payload drift was accepted.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_OFFICIAL_SNAPSHOT_UNSEALED, $exception->errorCode);
        }
    }

    public function test_forged_operational_seal_is_rejected_without_calling_verifier(): void
    {
        [$query, $snapshot, $result] = $this->fixture(false);
        $hash = (new CanonicalReportSourceHashBuilder())->build($query, $snapshot, $result);
        $seal = new ReportSnapshotSeal(
            'seal-key-1',
            'ed25519-sha256',
            $hash,
            rtrim(strtr(base64_encode(str_repeat("\0", 64)), '+/', '-_'), '='),
            $snapshot->generatedAt,
        );
        $forged = $this->forgeSnapshot($snapshot, ['sourceHash' => $hash, 'seal' => $seal]);
        $forgedResult = $this->withSnapshot($result, $forged, $hash);
        $verifier = new RecordingSealVerifier();

        try {
            (new ReportSnapshotSealValidator($verifier))->assertSealable($query, $forged, $forgedResult, $hash);
            self::fail('Operational seal was accepted.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_INTERNAL_ERROR, $exception->errorCode);
            self::assertSame(0, $verifier->calls);
        }
    }

    private function fixture(bool $official): array
    {
        $classification = $official ? ReportSnapshotClassification::OFFICIAL : ReportSnapshotClassification::OPERATIONAL;
        $definition = (new ReportDefinitionBuilder())->snapshotClassification($classification)->payload();
        $scope = new ReportScope(1, [1], [], [], new DateTimeZone('UTC'));
        $query = new ReportQuery($definition, $scope, new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet([]), [], new DateTimeImmutable('2026-07-29T07:00:00Z'), 'ru-RU');
        $placeholder = new Sha256Hash(str_repeat('c', 64));
        $seal = $official ? new ReportSnapshotSeal('seal-key-1', 'ed25519-sha256', $placeholder, rtrim(strtr(base64_encode(str_repeat("\0", 64)), '+/', '-_'), '='), new DateTimeImmutable('2026-07-29T08:00:00Z')) : null;
        $snapshot = new ReportSnapshotRef('materialized', 'snapshot-1', $scope, $definition->definitionHash, $definition->formulaVersion, $placeholder, new DateTimeImmutable('2026-07-29T07:00:00Z'), null, [], $classification, $seal);
        $result = $this->withSnapshot(new ReportResult(
            new ReportResultMetadata($snapshot, 1, $snapshot->generatedAt, null),
            [],
            ReportFreshnessStatus::FRESH,
            new ReportQuality(ReportQualityStatus::COMPLETE, null, [], 0, ReportReconciliationStatus::MATCHED, [], []),
            new ReportProvenance('primary', [new ReportSourceRef('source', 'table', 'snapshot', 'v1', 'v1', 1, new Sha256Hash(str_repeat('a', 64)))], $placeholder, null),
            [['id' => 'name']],
            [],
        ), $snapshot, $placeholder);

        return [$query, $snapshot, $result];
    }

    private function withSourceHash(ReportSnapshotRef $snapshot, Sha256Hash $hash): ReportSnapshotRef
    {
        $seal = $snapshot->seal === null ? null : new ReportSnapshotSeal($snapshot->seal->keyId, $snapshot->seal->algorithm, $hash, $snapshot->seal->signature, $snapshot->seal->sealedAt);

        return new ReportSnapshotRef($snapshot->kind, $snapshot->id, $snapshot->scope, $snapshot->definitionHash, $snapshot->formulaVersion, $hash, $snapshot->generatedAt, $snapshot->staleAt, $snapshot->watermarks, $snapshot->classification, $seal);
    }

    private function withSnapshot(ReportResult $result, ReportSnapshotRef $snapshot, Sha256Hash $hash): ReportResult
    {
        return new ReportResult(
            new ReportResultMetadata($snapshot, $result->metadata->rowCount, $snapshot->generatedAt, $snapshot->staleAt),
            $result->totals,
            $result->freshness,
            $result->quality,
            new ReportProvenance($result->provenance->sourceOfTruth, $result->provenance->sourceRefs, $hash, $result->provenance->externalConfirmationRole),
            $result->rowSchema,
            $result->capabilities,
        );
    }

    private function copySnapshot(
        ReportSnapshotRef $snapshot,
        ?string $id = null,
        ?ReportScope $scope = null,
        ?Sha256Hash $definitionHash = null,
        ?string $formulaVersion = null,
        ?Sha256Hash $sourceHash = null,
    ): ReportSnapshotRef {
        return new ReportSnapshotRef(
            $snapshot->kind,
            $id ?? $snapshot->id,
            $scope ?? $snapshot->scope,
            $definitionHash ?? $snapshot->definitionHash,
            $formulaVersion ?? $snapshot->formulaVersion,
            $sourceHash ?? $snapshot->sourceHash,
            $snapshot->generatedAt,
            $snapshot->staleAt,
            $snapshot->watermarks,
            $snapshot->classification,
            $snapshot->seal,
        );
    }

    private function copyResult(
        ReportResult $result,
        ?ReportResultMetadata $metadata = null,
        ?Sha256Hash $provenanceHash = null,
    ): ReportResult {
        return new ReportResult(
            $metadata ?? $result->metadata,
            $result->totals,
            $result->freshness,
            $result->quality,
            new ReportProvenance(
                $result->provenance->sourceOfTruth,
                $result->provenance->sourceRefs,
                $provenanceHash ?? $result->provenance->sourceHash,
                $result->provenance->externalConfirmationRole,
            ),
            $result->rowSchema,
            $result->capabilities,
        );
    }

    private function forgeMetadata(
        ReportSnapshotRef $snapshot,
        int $rowCount,
        DateTimeImmutable $generatedAt,
        ?DateTimeImmutable $staleAt,
    ): ReportResultMetadata {
        $reflection = new \ReflectionClass(ReportResultMetadata::class);
        $metadata = $reflection->newInstanceWithoutConstructor();
        foreach (compact('snapshot', 'rowCount', 'generatedAt', 'staleAt') as $property => $value) {
            $reflection->getProperty($property)->setValue($metadata, $value);
        }

        return $metadata;
    }

    private function forgeSnapshot(ReportSnapshotRef $prototype, array $overrides): ReportSnapshotRef
    {
        $reflection = new \ReflectionClass(ReportSnapshotRef::class);
        $snapshot = $reflection->newInstanceWithoutConstructor();
        foreach (get_object_vars($prototype) as $property => $value) {
            $reflection->getProperty($property)->setValue($snapshot, $overrides[$property] ?? $value);
        }

        return $snapshot;
    }

    private function forgeResult(ReportResult $prototype, array $overrides): ReportResult
    {
        $reflection = new \ReflectionClass(ReportResult::class);
        $result = $reflection->newInstanceWithoutConstructor();
        foreach (get_object_vars($prototype) as $property => $value) {
            $reflection->getProperty($property)->setValue($result, $overrides[$property] ?? $value);
        }

        return $result;
    }
}

final class RecordingSealVerifier implements ReportSnapshotSealVerifier
{
    public int $calls = 0;
    public ?ReportSnapshotSealVerificationInput $input = null;

    public function assertTrusted(ReportSnapshotSealVerificationInput $input): void
    {
        ++$this->calls;
        $this->input = $input;
    }
}
