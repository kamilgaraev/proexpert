<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Execution;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;

final readonly class CurrentReportAuthorizationTarget
{
    public function __construct(
        public ReportDefinition $definition,
        public ReportOperation $operation,
        public ?ReportSnapshotRef $snapshot,
        public ?string $exportFormat = null,
    ) {
        $snapshotRequired = in_array($operation, [
            ReportOperation::EXPORT,
            ReportOperation::DOWNLOAD,
            ReportOperation::DRILL_DOWN,
        ], true);

        if (($operation === ReportOperation::RUN && $snapshot !== null)
            || ($snapshotRequired && $snapshot === null)
            || ($exportFormat !== null && ! in_array($exportFormat, $definition->formats, true))
            || ($snapshot !== null
                && (! hash_equals($definition->definitionHash->value, $snapshot->definitionHash->value)
                    || ! hash_equals($definition->formulaVersion, $snapshot->formulaVersion)))) {
            throw new \InvalidArgumentException('current_report_authorization_target_invalid');
        }
    }

    public function canonicalFingerprint(): string
    {
        return hash('sha256', CanonicalJson::encode([
            'definition' => [
                'code' => $this->definition->code,
                'definition_hash' => $this->definition->definitionHash->value,
                'contract_version' => $this->definition->contractVersion,
                'core_access_mode' => $this->definition->coreAccessMode->value,
                'formula_version' => $this->definition->formulaVersion,
                'source_schema_version' => $this->definition->sourceSchemaVersion,
                'source_module' => $this->definition->sourceModule,
                'renderer_version' => $this->definition->rendererVersion,
                'filters' => $this->definition->filters,
                'columns' => $this->definition->columns,
                'sorts' => $this->definition->sorts,
                'formats' => $this->definition->formats,
                'permission_policy' => [
                    'view_permissions' => $this->definition->permissionPolicy->viewPermissions,
                    'export_permissions' => $this->definition->permissionPolicy->exportPermissions,
                    'sensitive_permissions' => $this->definition->permissionPolicy->sensitivePermissions,
                    'audit_permissions' => $this->definition->permissionPolicy->auditPermissions,
                ],
                'snapshot_classification' => $this->definition->snapshotClassification->value,
                'output_classification' => [
                    'default_classification' => $this->definition->outputClassification->defaultClassification->value,
                    'sensitive_column_ids' => $this->definition->outputClassification->sensitiveColumnIds,
                    'audit_column_ids' => $this->definition->outputClassification->auditColumnIds,
                    'totals_sensitive' => $this->definition->outputClassification->totalsSensitive,
                    'totals_audit' => $this->definition->outputClassification->totalsAudit,
                    'provenance_audit' => $this->definition->outputClassification->provenanceAudit,
                ],
                'publication_readiness' => $this->definition->publicationReadiness->value,
                'supports_subscriptions' => $this->definition->supportsSubscriptions,
            ],
            'operation' => $this->operation->value,
            'export_format' => $this->exportFormat,
            'snapshot' => $this->snapshot === null ? null : [
                'kind' => $this->snapshot->kind,
                'id' => $this->snapshot->id,
                'scope' => $this->snapshot->scope->canonicalIdentity(),
                'definition_hash' => $this->snapshot->definitionHash->value,
                'formula_version' => $this->snapshot->formulaVersion,
                'source_hash' => $this->snapshot->sourceHash->value,
                'generated_at' => $this->snapshot->generatedAt->format('Y-m-d\TH:i:s.uP'),
                'stale_at' => $this->snapshot->staleAt?->format('Y-m-d\TH:i:s.uP'),
                'watermarks' => $this->snapshot->watermarks,
                'classification' => $this->snapshot->classification->value,
                'seal' => $this->snapshot->seal === null ? null : [
                    'key_id' => $this->snapshot->seal->keyId,
                    'algorithm' => $this->snapshot->seal->algorithm,
                    'sealed_payload_hash' => $this->snapshot->seal->sealedPayloadHash->value,
                    'signature' => $this->snapshot->seal->signature,
                    'sealed_at' => $this->snapshot->seal->sealedAt->format('Y-m-d\TH:i:s.uP'),
                ],
            ],
        ]));
    }
}
