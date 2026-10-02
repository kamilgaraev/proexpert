<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseItemGallery;
use App\BusinessModules\Features\QualityControl\Models\QualityDefectPhoto;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyMedicalExam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AssistantOperationsNativeFileMetadata
{
    public const SOURCE = 'operations_native';
    public const MAX_BYTES = 25_000_000;

    public static function definitions(): array
    {
        return [
            'quality_defect_photo' => ['model' => QualityDefectPhoto::class, 'table' => 'quality_defect_photos', 'permissions' => ['quality-control.view'], 'identity' => 'photo_id', 'parent' => 'photo_id'],
            'safety_medical_exam' => ['model' => SafetyMedicalExam::class, 'table' => 'safety_medical_exams', 'permissions' => ['safety-management.view'], 'identity' => 'exam_id', 'parent' => 'exam_id'],
            'warehouse_item_gallery' => ['model' => WarehouseItemGallery::class, 'table' => 'warehouse_item_galleries', 'permissions' => ['warehouse.view'], 'identity' => 'file_id', 'parent' => 'gallery_id'],
        ];
    }

    public static function types(): array { return array_keys(self::definitions()); }
    public static function formats(): array { return AssistantLegalNativeFileMetadata::formats(); }

    public static function sourceQuery(string $type, int $organizationId): QueryBuilder
    {
        self::definitions()[$type] ?? throw new RuntimeException('ai_assistant_document_native_source_invalid');
        if ($type === 'quality_defect_photo') {
            $query = DB::table('quality_defect_photos as native_source')
                ->join('quality_defects as native_parent', 'native_parent.id', '=', 'native_source.quality_defect_id')
                ->whereColumn('native_parent.organization_id', 'native_source.organization_id')->whereNull('native_parent.deleted_at');
        } elseif ($type === 'safety_medical_exam') {
            $query = DB::table('safety_medical_exams as native_source')->whereNull('native_source.deleted_at')
                ->join('files as native_file', 'native_file.id', '=', 'native_source.file_id')->whereNull('native_file.deleted_at')
                ->whereColumn('native_file.organization_id', 'native_source.organization_id')
                ->join('workforce_employees as native_employee', 'native_employee.id', '=', 'native_source.employee_id')
                ->whereColumn('native_employee.organization_id', 'native_source.organization_id')->whereNull('native_employee.deleted_at')
                ->where(static function (QueryBuilder $owner): void {
                    $owner->where(static fn (QueryBuilder $empty) => $empty->whereNull('native_file.fileable_type')->whereNull('native_file.fileable_id'))
                        ->orWhere(static fn (QueryBuilder $exam) => $exam->where('native_file.fileable_type', (new SafetyMedicalExam)->getMorphClass())->whereColumn('native_file.fileable_id', 'native_source.id'))
                        ->orWhere(static fn (QueryBuilder $employee) => $employee->where('native_file.fileable_type', (new \App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee)->getMorphClass())->whereColumn('native_file.fileable_id', 'native_source.employee_id'));
                });
        } else {
            $query = DB::table('files as native_source')->whereNull('native_source.deleted_at')->where('native_source.type', 'photo')
                ->where('native_source.fileable_type', (new WarehouseItemGallery)->getMorphClass())
                ->join('warehouse_item_galleries as native_parent', 'native_parent.id', '=', 'native_source.fileable_id')
                ->whereColumn('native_parent.organization_id', 'native_source.organization_id')
                ->join('organization_warehouses as native_warehouse', 'native_warehouse.id', '=', 'native_parent.warehouse_id')
                ->whereColumn('native_warehouse.organization_id', 'native_source.organization_id')
                ->join('materials as native_material', 'native_material.id', '=', 'native_parent.material_id')
                ->whereColumn('native_material.organization_id', 'native_source.organization_id')->whereNull('native_material.deleted_at');
        }
        if ($organizationId > 0) { $query->where('native_source.organization_id', $organizationId); }
        return $query;
    }

    public static function versionExpressions(string $type): array
    {
        self::definitions()[$type] ?? throw new RuntimeException('ai_assistant_document_native_source_invalid');
        $data = ['id' => 'native_source.id', 'organization_id' => 'native_source.organization_id', 'created_at' => 'native_source.created_at', 'updated_at' => 'native_source.updated_at'];
        if ($type === 'quality_defect_photo') {
            foreach (['quality_defect_id', 'uploaded_by', 'type', 'caption', 'storage_etag', 'storage_identity_verified'] as $field) { $data[$field] = 'native_source.'.$field; }
            return $data + ['native_parent_id' => 'native_source.id', 'native_file_id' => 'NULL', 'storage_path' => 'native_source.url', 'expected_sha256' => 'native_source.storage_sha256',
                'size_bytes' => 'native_source.size_bytes', 'mime_type' => 'native_source.mime_type', 'actor_user_id' => 'native_source.uploaded_by',
                'parent_project_id' => 'native_parent.project_id', 'parent_updated_at' => 'native_parent.updated_at'];
        }
        $file = $type === 'safety_medical_exam' ? 'native_file' : 'native_source';
        $data += ['native_file_id' => $file.'.id', 'storage_path' => $file.'.path', 'size_bytes' => $file.'.size', 'mime_type' => $file.'.mime_type',
            'actor_user_id' => $file.'.user_id', 'file_updated_at' => $file.'.updated_at', 'fileable_type' => $file.'.fileable_type', 'fileable_id' => $file.'.fileable_id', 'disk' => $file.'.disk',
            'expected_sha256' => $file.".additional_info->>'sha256'"];
        if ($type === 'safety_medical_exam') {
            return $data + ['native_parent_id' => 'native_source.id', 'employee_id' => 'native_source.employee_id', 'completed_at' => 'native_source.completed_at',
                'valid_until' => 'native_source.valid_until', 'result' => 'native_source.result', 'parent_project_id' => 'NULL', 'employee_updated_at' => 'native_employee.updated_at'];
        }
        return $data + ['native_parent_id' => 'native_parent.id', 'warehouse_id' => 'native_parent.warehouse_id', 'material_id' => 'native_parent.material_id',
            'parent_project_id' => 'native_warehouse.project_id', 'parent_updated_at' => 'native_parent.updated_at', 'warehouse_updated_at' => 'native_warehouse.updated_at', 'material_updated_at' => 'native_material.updated_at'];
    }

    public static function sourceColumns(string $type): array
    {
        $columns = [];
        foreach (self::versionExpressions($type) as $field => $expression) { $columns[] = DB::raw($expression.' as '.$field); }
        return $columns;
    }

    public static function versionData(string $type, array $source): array
    {
        $data = ['native_entity_type' => $type];
        foreach (array_keys(self::versionExpressions($type)) as $field) {
            $value = $source[$field] ?? null;
            if (! is_scalar($value) && $value !== null) { throw new RuntimeException('ai_assistant_document_native_source_invalid'); }
            $data[$field] = is_bool($value) ? ($value ? 'true' : 'false') : ($value === null ? null : (string) $value);
        }
        return $data;
    }

    public static function fingerprint(array $version): string { return hash('sha256', json_encode($version, JSON_THROW_ON_ERROR)); }
    public static function filename(array $source): string { return basename((string) $source['storage_path']); }
    public static function projectId(array $source): ?int { return isset($source['parent_project_id']) && (int) $source['parent_project_id'] > 0 ? (int) $source['parent_project_id'] : null; }
    public static function isStorageIdentityVerified(mixed $value): bool { return in_array($value, [true, 'true', 't', 1, '1'], true); }

    public static function assertLegacyQualityDefectPhotoSource(int $organizationId, array $source): void
    {
        $organization = (int) ($source['organization_id'] ?? 0);
        $photoId = (string) ($source['id'] ?? '');
        $defectId = (string) ($source['quality_defect_id'] ?? '');
        $path = (string) ($source['storage_path'] ?? '');
        $unverified = $source['storage_identity_verified'] ?? null;
        $legacyUnverified = in_array($unverified, [false, 'false', 'f', 0, '0'], true);
        $uuid = '[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}';

        if ($organizationId < 1 || $organization !== $organizationId
            || ! preg_match('/^[1-9][0-9]*$/D', $photoId)
            || ! preg_match('/^[1-9][0-9]*$/D', $defectId)
            || (string) ($source['native_parent_id'] ?? '') !== $photoId
            || ($source['native_file_id'] ?? null) !== null
            || ! $legacyUnverified
            || ($source['storage_etag'] ?? null) !== null
            || ($source['expected_sha256'] ?? null) !== null
            || ($source['size_bytes'] ?? null) !== null
            || ($source['mime_type'] ?? null) !== null
            || strlen($path) > 1024
            || ! preg_match('#^org-'.$organization.'/quality-control/defects/'.$defectId.'/'.$uuid.'\.(?:png|jpe?g|webp)$#Di', $path)) {
            throw new RuntimeException('ai_assistant_document_native_source_invalid');
        }
    }

    public static function assertSource(string $type, array $source): void
    {
        self::definitions()[$type] ?? throw new RuntimeException('ai_assistant_document_native_source_invalid');
        $org = (int) ($source['organization_id'] ?? 0);
        $path = (string) ($source['storage_path'] ?? '');
        $size = $source['size_bytes'] ?? null;
        $hash = $source['expected_sha256'] ?? null;
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($org < 1 || ! preg_match('/^[1-9][0-9]*$/D', (string) ($source['id'] ?? '')) || ! preg_match('/^[1-9][0-9]*$/D', (string) ($source['native_parent_id'] ?? ''))
            || strlen($path) > 1024 || ! str_starts_with($path, 'org-'.$org.'/') || str_contains($path, '..') || str_contains($path, '\\')
            || preg_match('/[\x00-\x20\x7f%?#:]/', $path) || ! isset(self::formats()[$extension])
            || ! preg_match('/^[1-9][0-9]*$/D', (string) $size) || (int) $size > self::MAX_BYTES
            || (string) ($source['mime_type'] ?? '') !== self::formats()[$extension] || ($hash !== null && ! preg_match('/^[a-f0-9]{64}$/D', (string) $hash))) {
            throw new RuntimeException('ai_assistant_document_native_source_invalid');
        }
        $uuid = '[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}';
        if ($type === 'quality_defect_photo') {
            $etag = $source['storage_etag'] ?? null;
            if (($source['native_file_id'] ?? null) !== null || (string) $source['native_parent_id'] !== (string) $source['id'] || ! self::isStorageIdentityVerified($source['storage_identity_verified'] ?? null) || $hash === null
                || ! is_string($etag) || $etag === '' || strlen($etag) > 255 || preg_match('/[\x00-\x1F\x7F]/', $etag)
                || ! preg_match('#^org-'.$org.'/quality-control/defects/'.(int) ($source['quality_defect_id'] ?? 0).'/'.$uuid.'\.(?:png|jpe?g|webp)$#Di', $path)) {
                throw new RuntimeException('ai_assistant_document_native_source_invalid');
            }
        } elseif (! preg_match('/^[1-9][0-9]*$/D', (string) ($source['native_file_id'] ?? ''))) {
            throw new RuntimeException('ai_assistant_document_native_source_invalid');
        } elseif ($type === 'warehouse_item_gallery' && ((string) $source['id'] !== (string) $source['native_file_id'] || ! preg_match('#^org-'.$org.'/warehouse/balances/warehouse-'.(int) ($source['warehouse_id'] ?? 0).'/material-'.(int) ($source['material_id'] ?? 0).'/'.$uuid.'\.(?:png|jpe?g|webp)$#Di', $path))) {
            throw new RuntimeException('ai_assistant_document_native_source_invalid');
        }
    }

    public static function constrainDocuments(Builder|QueryBuilder $query): void
    {
        $query->whereNull('ai_assistant_documents.file_id')->where('ai_assistant_documents.metadata->assistant_native_source', self::SOURCE)
            ->whereBetween('ai_assistant_documents.size_bytes', [1, self::MAX_BYTES])
            ->whereRaw("(ai_assistant_documents.metadata->>'native_source_sha256') ~ '^[a-f0-9]{64}$'")
            ->whereRaw("ai_assistant_documents.checksum = ai_assistant_documents.metadata->>'native_source_sha256'")
            ->where(static function (Builder|QueryBuilder $types): void {
                $types->whereRaw('1 = 0');
                foreach (self::types() as $type) {
                    $types->orWhere(static function (Builder|QueryBuilder $branch) use ($type): void {
                        $native = self::sourceQuery($type, 0)->selectRaw('1')
                            ->whereRaw("CAST(native_source.id AS TEXT) = ai_assistant_documents.metadata->>'native_source_id'")
                            ->whereRaw(self::versionExpressions($type)['native_parent_id']."::text = ai_assistant_documents.parent_entity_id")
                            ->whereColumn('native_source.organization_id', 'ai_assistant_documents.organization_id');
                        foreach (self::versionExpressions($type) as $field => $expression) {
                            $native->whereRaw("CAST($expression AS TEXT) IS NOT DISTINCT FROM ai_assistant_documents.metadata->'native_source_fields'->>'$field'");
                        }
                        $branch->where('ai_assistant_documents.parent_entity_type', $type)
                            ->where('ai_assistant_documents.metadata->native_source_type', $type)->whereExists($native);
                    });
                }
            });
    }
}
