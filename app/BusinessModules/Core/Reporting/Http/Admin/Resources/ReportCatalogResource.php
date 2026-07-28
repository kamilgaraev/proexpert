<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Resources;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogView;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReportCatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        assert($this->resource instanceof ReportCatalogView);

        return [
            'contract_version' => $this->resource->contractVersion,
            'manifest_sha256' => $this->resource->manifestSha256->value,
            'definitions' => array_map($this->definition(...), $this->resource->definitions),
        ];
    }

    private function definition(ReportDefinition $definition): array
    {
        return [
            'code' => $definition->code,
            'definition_hash' => $definition->definitionHash->value,
            'contract_version' => $definition->contractVersion,
            'formula_version' => $definition->formulaVersion,
            'source_schema_version' => $definition->sourceSchemaVersion,
            'renderer_version' => $definition->rendererVersion,
            'filters' => $definition->filters,
            'columns' => $definition->columns,
            'sorts' => $definition->sorts,
            'formats' => $definition->formats,
            'permission_policy' => [
                'view' => $definition->permissionPolicy->viewPermissions,
                'export' => $definition->permissionPolicy->exportPermissions,
                'sensitive' => $definition->permissionPolicy->sensitivePermissions,
                'audit' => $definition->permissionPolicy->auditPermissions,
            ],
            'snapshot_classification' => $definition->snapshotClassification->value,
            'output_classification' => [
                'default_classification' => $definition->outputClassification->defaultClassification->value,
                'sensitive_column_ids' => $definition->outputClassification->sensitiveColumnIds,
                'audit_column_ids' => $definition->outputClassification->auditColumnIds,
                'totals_sensitive' => $definition->outputClassification->totalsSensitive,
                'totals_audit' => $definition->outputClassification->totalsAudit,
                'provenance_audit' => $definition->outputClassification->provenanceAudit,
            ],
            'publication_readiness' => $definition->publicationReadiness->value,
            'supports_subscriptions' => $definition->supportsSubscriptions,
        ];
    }
}
