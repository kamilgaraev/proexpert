<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Resources;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogView;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogDefinitionView;
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

    private function definition(ReportDefinition|ReportCatalogDefinitionView $definition): array
    {
        if ($definition instanceof ReportCatalogDefinitionView) {
            return [
                'code' => $definition->code,
                'title_key' => $definition->titleKey,
                'catalog_group' => $definition->catalogGroup->value,
                'category' => $definition->category,
                'grain' => $definition->grain,
                'wave' => $definition->wave,
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
                'supports_subscriptions' => $definition->scheduling->supportsSubscriptions,
                'reproducible_scheduled_snapshot' => $definition->scheduling->reproducibleScheduledSnapshot,
                'visibility' => [
                    'can_view' => $definition->visibility->canView,
                    'can_run' => $definition->visibility->canRun,
                    'can_export' => $definition->visibility->canExport,
                    'can_download' => $definition->visibility->canDownload,
                    'can_manage' => $definition->visibility->canManage,
                    'can_view_sensitive' => $definition->visibility->canViewSensitive,
                    'can_view_audit' => $definition->visibility->canViewAudit,
                ],
            ];
        }

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
