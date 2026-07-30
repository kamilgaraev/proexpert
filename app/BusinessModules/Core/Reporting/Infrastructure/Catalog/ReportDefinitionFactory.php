<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final class ReportDefinitionFactory
{
    public function fromManifest(array $row): ReportDefinition
    {
        $versions = $this->map($row, 'versions');
        $permissions = $this->map($row, 'permissions');
        $readiness = $this->map($row, 'readiness');
        $capabilities = $this->map($row, 'capabilities');

        return new ReportDefinition(
            code: $this->string($row, 'code'),
            definitionHash: new Sha256Hash(hash('sha256', CanonicalJson::encode($row))),
            contractVersion: $this->string($versions, 'contract'),
            formulaVersion: $this->string($versions, 'formula'),
            sourceSchemaVersion: $this->string($versions, 'source_schema'),
            rendererVersion: $this->string($versions, 'renderer'),
            filters: $this->list($row, 'filters'),
            columns: $this->list($row, 'columns'),
            sorts: $this->list($row, 'sorts'),
            formats: $this->list($row, 'formats'),
            permissionPolicy: new ReportPermissionPolicy(
                $this->list($permissions, 'view'),
                $this->list($permissions, 'export'),
                $this->list($permissions, 'sensitive'),
                $this->list($permissions, 'audit'),
            ),
            snapshotClassification: ReportSnapshotClassification::OPERATIONAL,
            outputClassification: new ReportOutputClassification(
                ReportDataClassification::STANDARD,
                [],
                [],
                false,
                false,
                false,
            ),
            publicationReadiness: ReportPublicationReadiness::from($this->string($readiness, 'publication')),
            supportsSubscriptions: $this->boolean($capabilities, 'supports_subscriptions'),
        );
    }

    public function metadataFromManifest(array $row, int $manifestOrdinal): ReportCatalogMetadata
    {
        return new ReportCatalogMetadata(
            code: $this->string($row, 'code'),
            titleKey: $this->string($row, 'title_key'),
            catalogGroup: ReportCatalogGroup::from($this->string($row, 'catalog_group')),
            category: $this->string($row, 'category'),
            grain: $this->string($row, 'grain'),
            wave: $this->integer($row, 'wave'),
            manifestOrdinal: $manifestOrdinal,
        );
    }

    public function schedulingFromManifest(array $row): ReportSchedulingCapability
    {
        $capabilities = $this->map($row, 'capabilities');

        return new ReportSchedulingCapability(
            code: $this->string($row, 'code'),
            supportsSubscriptions: $this->boolean($capabilities, 'supports_subscriptions'),
            reproducibleScheduledSnapshot: $this->boolean($capabilities, 'reproducible_scheduled_snapshot'),
        );
    }

    private function map(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('report_manifest_definition_invalid');
        }

        return $value;
    }

    private function list(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('report_manifest_definition_invalid');
        }

        return $value;
    }

    private function string(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (! is_string($value)) {
            throw new InvalidArgumentException('report_manifest_definition_invalid');
        }

        return $value;
    }

    private function integer(array $source, string $key): int
    {
        $value = $source[$key] ?? null;
        if (! is_int($value)) {
            throw new InvalidArgumentException('report_manifest_definition_invalid');
        }

        return $value;
    }

    private function boolean(array $source, string $key): bool
    {
        $value = $source[$key] ?? null;
        if (! is_bool($value)) {
            throw new InvalidArgumentException('report_manifest_definition_invalid');
        }

        return $value;
    }
}
