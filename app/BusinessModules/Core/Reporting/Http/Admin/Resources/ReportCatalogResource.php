<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Resources;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogDefinitionView;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogView;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReportCatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        assert($this->resource instanceof ReportCatalogView);

        return self::payload(
            $this->resource->contractVersion,
            $this->resource->manifestSha256,
            $this->resource->definitions,
        );
    }

    public static function payload(string $contractVersion, Sha256Hash $manifestSha256, array $definitions): array
    {
        foreach ($definitions as $definition) {
            if (! $definition instanceof ReportCatalogDefinitionView) {
                throw new \InvalidArgumentException('report_catalog_resource_invalid');
            }
        }

        return [
            'contract_version' => $contractVersion,
            'manifest_sha256' => $manifestSha256->value,
            'definitions' => array_map(static fn (ReportCatalogDefinitionView $definition): array => [
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
            ], $definitions),
        ];
    }
}
