<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Execution;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportSnapshotSealVerifier;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;

final readonly class ReportSnapshotSealValidator
{
    public function __construct(private ReportSnapshotSealVerifier $verifier)
    {
    }

    public function assertSealable(
        ReportQuery $query,
        ReportSnapshotRef $snapshot,
        ReportResult $result,
        Sha256Hash $calculatedSourceHash,
    ): void {
        $definition = $query->definition;
        $metadata = $result->metadata;
        $valid = hash_equals($definition->definitionHash->value, $snapshot->definitionHash->value)
            && $query->scope->canonicalIdentity() === $snapshot->scope->canonicalIdentity()
            && CanonicalJson::encode($this->snapshotProjection($metadata->snapshot)) === CanonicalJson::encode($this->snapshotProjection($snapshot))
            && $this->utc($metadata->generatedAt) === $this->utc($snapshot->generatedAt)
            && $this->nullableUtc($metadata->staleAt) === $this->nullableUtc($snapshot->staleAt)
            && $snapshot->classification === $definition->snapshotClassification
            && $snapshot->formulaVersion === $definition->formulaVersion
            && hash_equals($snapshot->sourceHash->value, $calculatedSourceHash->value)
            && hash_equals($result->provenance->sourceHash->value, $calculatedSourceHash->value);

        if (!$valid) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        if ($snapshot->classification === ReportSnapshotClassification::OFFICIAL) {
            if ($snapshot->seal === null || !hash_equals($snapshot->seal->sealedPayloadHash->value, $calculatedSourceHash->value)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_OFFICIAL_SNAPSHOT_UNSEALED);
            }
            $this->verifier->assertTrusted(new ReportSnapshotSealVerificationInput(
                $snapshot->seal,
                $snapshot->id,
                $snapshot->kind,
                $snapshot->classification,
                $snapshot->generatedAt,
                $calculatedSourceHash,
            ));
        } elseif ($snapshot->seal !== null) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }
    }

    private function snapshotProjection(ReportSnapshotRef $snapshot): array
    {
        return [
            'kind' => $snapshot->kind,
            'id' => $snapshot->id,
            'scope' => $snapshot->scope->canonicalIdentity(),
            'definition_hash' => $snapshot->definitionHash->value,
            'formula_version' => $snapshot->formulaVersion,
            'source_hash' => $snapshot->sourceHash->value,
            'generated_at' => $this->utc($snapshot->generatedAt),
            'stale_at' => $this->nullableUtc($snapshot->staleAt),
            'watermarks' => $snapshot->watermarks,
            'classification' => $snapshot->classification->value,
            'seal' => $snapshot->seal === null ? null : [
                'key_id' => $snapshot->seal->keyId,
                'algorithm' => $snapshot->seal->algorithm,
                'sealed_payload_hash' => $snapshot->seal->sealedPayloadHash->value,
                'signature' => $snapshot->seal->signature,
                'sealed_at' => $this->utc($snapshot->seal->sealedAt),
            ],
        ];
    }

    private function utc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function nullableUtc(?DateTimeImmutable $value): ?string
    {
        return $value === null ? null : $this->utc($value);
    }
}
