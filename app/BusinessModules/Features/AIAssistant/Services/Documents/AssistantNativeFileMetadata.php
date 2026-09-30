<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforceExportPackageFileRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RuntimeException;

final class AssistantNativeFileMetadata
{
    public const ENTITY_TYPE = 'workforce_export_package_file';
    public const MODEL = AssistantWorkforceExportPackageFileRecord::class;
    public const SOURCE = 'workforce_export';
    public const PERMISSIONS = ['workforce.view', 'workforce.payroll-source.manage', 'finance.view', 'workforce.exports.generate'];
    public const MAX_BYTES = 25_000_000;

    public static function types(): array { return [self::ENTITY_TYPE]; }

    public static function formats(): array
    {
        return ['source_json' => ['payroll-source.json', 'application/json'],
            'source_csv' => ['payroll-source.csv', 'text/csv'], 'summary_csv' => ['payroll-summary.csv', 'text/csv']];
    }

    public static function assertSource(array $source, array $package, array $period): void
    {
        $organizationId = (int) ($source['organization_id'] ?? 0);
        $periodId = (int) ($package['payroll_period_id'] ?? 0);
        $format = self::formats()[$source['file_type'] ?? ''] ?? null;
        $numberPrefix = 'WF-'.$periodId.'-';
        $packageNumber = (string) ($package['package_number'] ?? '');
        $key = str_starts_with($packageNumber, $numberPrefix) ? substr($packageNumber, strlen($numberPrefix)) : '';
        $size = $source['size_bytes'] ?? null;
        if ($organizationId < 1 || $format === null || ! preg_match('/^[0-9]{14}-[a-f0-9]{16}$/D', $key)
            || (int) ($source['export_package_id'] ?? 0) !== (int) ($package['id'] ?? 0)
            || (int) ($package['organization_id'] ?? 0) !== $organizationId || (int) ($period['organization_id'] ?? 0) !== $organizationId
            || (int) ($period['id'] ?? 0) !== $periodId || ($source['storage_disk'] ?? null) !== 's3'
            || ($source['file_name'] ?? null) !== $format[0] || ! is_numeric($size) || (int) $size < 1 || (int) $size > self::MAX_BYTES
            || ($source['storage_path'] ?? null) !== 'org-'.$organizationId.'/workforce/payroll-exports/period-'.$periodId.'/package-'.$key.'/'.$format[0]
            || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($package['source_hash'] ?? ''))) {
            throw new RuntimeException('ai_assistant_document_native_source_invalid');
        }
    }

    public static function versionData(array $source, array $package, array $period): array
    {
        return ['id' => (string) $source['id'], 'organization_id' => (string) $source['organization_id'],
            'export_package_id' => (string) $source['export_package_id'], 'storage_disk' => (string) $source['storage_disk'],
            'storage_path' => (string) $source['storage_path'], 'file_name' => (string) $source['file_name'],
            'file_type' => (string) $source['file_type'], 'size_bytes' => (string) $source['size_bytes'],
            'updated_at' => (string) $source['updated_at'], 'package_source_hash' => (string) $package['source_hash'],
            'package_number' => (string) $package['package_number'], 'payroll_period_id' => (string) $package['payroll_period_id'],
            'payroll_project_id' => $period['project_id'] === null ? null : (string) $period['project_id']];
    }

    public static function fingerprint(array $version): string
    {
        return hash('sha256', json_encode($version, JSON_THROW_ON_ERROR));
    }

    public static function constrainMappings(Builder|QueryBuilder $query): void
    {
        $query->where('files.additional_info->assistant_native_source', self::SOURCE)
            ->where('files.additional_info->native_entity_type', self::ENTITY_TYPE)
            ->whereRaw("(files.additional_info->>'native_source_sha256') ~ '^[a-f0-9]{64}$'")
            ->whereExists(static function (QueryBuilder $native): void {
                $native->selectRaw('1')->from('workforce_export_package_files as native_source')
                    ->join('workforce_export_packages as native_package', 'native_package.id', '=', 'native_source.export_package_id')
                    ->join('workforce_payroll_periods as native_period', 'native_period.id', '=', 'native_package.payroll_period_id')
                    ->whereColumn('native_source.id', 'files.fileable_id')->whereColumn('native_source.organization_id', 'files.organization_id')
                    ->whereColumn('native_package.organization_id', 'files.organization_id')->whereColumn('native_period.organization_id', 'files.organization_id')
                    ->where('native_source.storage_disk', 's3')->whereColumn('native_source.storage_path', 'files.path')
                    ->whereColumn('native_source.file_name', 'files.original_name')->whereColumn('native_source.size_bytes', 'files.size');
                $native->where(static function (QueryBuilder $formats): void {
                    $formats->whereRaw('1 = 0');
                    foreach (self::formats() as $type => [$name, $mime]) {
                        $formats->orWhere(static function (QueryBuilder $format) use ($type, $name, $mime): void {
                            $format->where('native_source.file_type', $type)->where('native_source.file_name', $name)->where('files.mime_type', $mime);
                        });
                    }
                });
                foreach (['id', 'organization_id', 'export_package_id', 'storage_disk', 'storage_path', 'file_name', 'file_type', 'size_bytes'] as $column) {
                    $native->whereRaw("CAST(native_source.$column AS TEXT) = files.additional_info->'native_source_fields'->>'$column'");
                }
                $native->whereRaw("CAST(native_source.updated_at AS TEXT) = files.additional_info->'native_source_fields'->>'updated_at'")
                    ->whereRaw("native_package.source_hash = files.additional_info->'native_source_fields'->>'package_source_hash'")
                    ->whereRaw("native_package.package_number = files.additional_info->'native_source_fields'->>'package_number'")
                    ->whereRaw("CAST(native_package.payroll_period_id AS TEXT) = files.additional_info->'native_source_fields'->>'payroll_period_id'")
                    ->whereRaw("CAST(native_period.project_id AS TEXT) IS NOT DISTINCT FROM files.additional_info->'native_source_fields'->>'payroll_project_id'");
            });
    }
}
