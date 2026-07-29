<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ReportAuthorizationSubject
{
    public function __construct(
        public ReportDispatchAggregate $aggregateKind,
        public string $aggregateId,
        public ReportDefinition $definition,
        public ReportScope $scope,
        public ?ReportSnapshotRef $snapshot,
        public ?string $parentRunId,
        public ?Sha256Hash $artifactIdentityHash,
    ) {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $aggregateId) !== 1) {
            throw new InvalidArgumentException('report_authorization_subject_invalid');
        }

        if ($aggregateKind === ReportDispatchAggregate::RUN) {
            if ($parentRunId !== null || $artifactIdentityHash !== null) {
                throw new InvalidArgumentException('report_authorization_subject_invalid');
            }
        } elseif (
            $snapshot === null
            || $parentRunId === null
            || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $parentRunId) !== 1
        ) {
            throw new InvalidArgumentException('report_authorization_subject_invalid');
        }

        if ($snapshot !== null && (
            $snapshot->scope->canonicalIdentity() !== $scope->canonicalIdentity()
            || $snapshot->definitionHash->value !== $definition->definitionHash->value
            || $snapshot->formulaVersion !== $definition->formulaVersion
        )) {
            throw new InvalidArgumentException('report_authorization_subject_invalid');
        }
    }

    public function canonicalFingerprint(): string
    {
        $snapshot = $this->snapshot;
        $seal = $snapshot?->seal;

        return hash('sha256', CanonicalJson::encode([
            'aggregate_kind' => $this->aggregateKind->value,
            'aggregate_id' => $this->aggregateId,
            'definition' => [
                'code' => $this->definition->code,
                'definition_hash' => $this->definition->definitionHash->value,
                'contract_version' => $this->definition->contractVersion,
                'formula_version' => $this->definition->formulaVersion,
                'source_schema_version' => $this->definition->sourceSchemaVersion,
                'renderer_version' => $this->definition->rendererVersion,
            ],
            'scope' => $this->scope->canonicalIdentity(),
            'snapshot' => $snapshot === null ? null : [
                'kind' => $snapshot->kind,
                'id' => $snapshot->id,
                'definition_hash' => $snapshot->definitionHash->value,
                'formula_version' => $snapshot->formulaVersion,
                'source_hash' => $snapshot->sourceHash->value,
                'generated_at' => $snapshot->generatedAt->format('Y-m-d\TH:i:s.uP'),
                'stale_at' => $snapshot->staleAt?->format('Y-m-d\TH:i:s.uP'),
                'watermarks' => $snapshot->watermarks,
                'classification' => $snapshot->classification->value,
                'seal' => $seal === null ? null : [
                    'key_id' => $seal->keyId,
                    'algorithm' => $seal->algorithm,
                    'sealed_payload_hash' => $seal->sealedPayloadHash->value,
                    'signature' => $seal->signature,
                    'sealed_at' => $seal->sealedAt->format('Y-m-d\TH:i:s.uP'),
                ],
            ],
            'parent_run_id' => $this->parentRunId,
            'artifact_identity_hash' => $this->artifactIdentityHash?->value,
        ]));
    }
}
