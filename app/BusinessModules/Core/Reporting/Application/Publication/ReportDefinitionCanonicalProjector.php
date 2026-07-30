<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;

final class ReportDefinitionCanonicalProjector
{
    public function project(ReportDefinition $definition): array
    {
        return [
            'code' => $definition->code,
            'columns' => $definition->columns,
            'contract_version' => $definition->contractVersion,
            'definition_hash' => $definition->definitionHash->value,
            'filters' => $definition->filters,
            'formats' => $definition->formats,
            'formula_version' => $definition->formulaVersion,
            'output_classification' => [
                'audit_column_ids' => $definition->outputClassification->auditColumnIds,
                'default' => $definition->outputClassification->defaultClassification->value,
                'provenance_audit' => $definition->outputClassification->provenanceAudit,
                'sensitive_column_ids' => $definition->outputClassification->sensitiveColumnIds,
                'totals_audit' => $definition->outputClassification->totalsAudit,
                'totals_sensitive' => $definition->outputClassification->totalsSensitive,
            ],
            'permission_policy' => [
                'audit' => $definition->permissionPolicy->auditPermissions,
                'export' => $definition->permissionPolicy->exportPermissions,
                'sensitive' => $definition->permissionPolicy->sensitivePermissions,
                'view' => $definition->permissionPolicy->viewPermissions,
            ],
            'publication_readiness' => $definition->publicationReadiness->value,
            'renderer_version' => $definition->rendererVersion,
            'snapshot_classification' => $definition->snapshotClassification->value,
            'sorts' => $definition->sorts,
            'source_schema_version' => $definition->sourceSchemaVersion,
            'supports_subscriptions' => $definition->supportsSubscriptions,
        ];
    }

    public function equals(ReportDefinition $left, ReportDefinition $right): bool
    {
        return CanonicalJson::encode($this->project($left))
            === CanonicalJson::encode($this->project($right));
    }
}
