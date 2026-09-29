<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\Jobs\ProcessAssistantDocument;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AssistantOperationsNativeFileIndexer
{
    public function __construct(private readonly AssistantOperationsNativeFileAdapter $native) {}

    public function prepare(int $organizationId, ?int $projectId = null, ?string $entityType = null, string|int|null $entityId = null, ?callable $heartbeat = null): void
    {
        if ($organizationId < 1 || (($entityType === null) !== ($entityId === null))) { return; }
        foreach (AssistantOperationsNativeFileMetadata::types() as $type) {
            $column = $entityType === null ? null : self::mutationColumn($type, $entityType);
            if ($entityType !== null && $column === null) { continue; }
            $query = AssistantOperationsNativeFileMetadata::sourceQuery($type, $organizationId);
            $project = AssistantOperationsNativeFileMetadata::versionExpressions($type)['parent_project_id'];
            if ($project !== 'NULL') {
                $projects = Project::query()->where(static fn (Builder $scope) => $scope->where('organization_id', $organizationId)
                    ->orWhereIn('id', DB::table('project_organization')->where('organization_id', $organizationId)->where('is_active', true)->select('project_id')))->select('id');
                $query->where(static fn (QueryBuilder $scope) => $scope->whereRaw($project.' IS NULL')->orWhereIn(DB::raw($project), $projects));
                if ($projectId !== null) { $query->whereRaw($project.' = ?', [$projectId]); }
            } elseif ($projectId !== null) {
                $query->whereIn('native_source.employee_id', DB::table('workforce_employee_assignments')->where('organization_id', $organizationId)
                    ->where('project_id', $projectId)->whereNull('deleted_at')->where('status', 'active')
                    ->whereDate('valid_from', '<=', now()->toDateString())->where(static fn (QueryBuilder $period) => $period->whereNull('valid_to')->orWhereDate('valid_to', '>=', now()->toDateString()))->select('employee_id'));
            }
            if ($column !== null) { $query->where($column, $entityId); }
            foreach ($query->select('native_source.id')->reorder()->lazyById(50, 'native_source.id', 'id') as $row) {
                try { $document = $this->native->mapForIndexing($organizationId, (string) $row->id, $type); }
                catch (RuntimeException $exception) {
                    if ($exception->getMessage() !== 'ai_assistant_document_native_source_invalid') { throw $exception; }
                    $document = null;
                }
                if ($document === null) {
                    $this->markAccessReview($organizationId, $type, (string) $row->id);
                } elseif ($document->status === AIAssistantDocument::STATUS_QUEUED) {
                    $documentId = (int) $document->id;
                    DB::afterCommit(static fn () => ProcessAssistantDocument::dispatch($documentId));
                }
                if ($heartbeat !== null) { $heartbeat(); }
            }
            $this->pruneMissing($organizationId, $projectId, $type, $entityType, $entityId, $heartbeat);
        }
    }

    private function pruneMissing(int $organizationId, ?int $projectId, string $type, ?string $entityType, string|int|null $entityId, ?callable $heartbeat): void
    {
        $documents = AIAssistantDocument::query()->where('organization_id', $organizationId)
            ->where('metadata->assistant_native_source', AssistantOperationsNativeFileMetadata::SOURCE)
            ->where('metadata->native_source_type', $type)->where('coverage_status', '!=', 'stale');
        if ($projectId !== null) {
            if ($type === 'safety_medical_exam') { return; }
            $documents->where('project_id', $projectId);
        }
        if ($entityType !== null) {
            $field = match ($entityType) {
                'quality_defect' => 'native_source_fields->quality_defect_id',
                'workforce_employee' => 'native_source_fields->employee_id',
                'warehouse' => 'native_source_fields->warehouse_id',
                'material' => 'native_source_fields->material_id',
                'file' => 'native_file_id',
                default => $type === 'warehouse_item_gallery' ? 'native_parent_id' : 'native_source_id',
            };
            $documents->where('metadata->'.$field, (string) $entityId);
        }
        $current = AssistantOperationsNativeFileMetadata::sourceQuery($type, $organizationId)->selectRaw('1')
            ->whereRaw("CAST(native_source.id AS TEXT) = ai_assistant_documents.metadata->>'native_source_id'");
        $documents->whereNotExists($current);
        foreach ($documents->lazyById(50) as $document) {
            DB::transaction(static function () use ($document, $organizationId): void {
                $document->update(['status' => AIAssistantDocument::STATUS_FAILED, 'coverage_status' => 'stale', 'last_error' => 'native_source_removed']);
                app(RagIndexingCoordinator::class)->queueEntity($organizationId, $document->project_id, 'file_document', 'assistant_document', $document->id);
            });
            if ($heartbeat !== null) { $heartbeat(); }
        }
    }

    private function markAccessReview(int $organizationId, string $type, string $sourceId): void
    {
        foreach (AIAssistantDocument::query()->where('organization_id', $organizationId)->where('metadata->assistant_native_source', AssistantOperationsNativeFileMetadata::SOURCE)
            ->where('metadata->native_source_type', $type)->where('metadata->native_source_id', $sourceId)->lazyById(50) as $document) {
            DB::transaction(static function () use ($document, $organizationId): void {
                $document->update(['status' => AIAssistantDocument::STATUS_FAILED, 'coverage_status' => 'needs_access_review', 'last_error' => 'native_actor_access_unavailable']);
                app(RagIndexingCoordinator::class)->queueEntity($organizationId, $document->project_id, 'file_document', 'assistant_document', $document->id);
            });
        }
    }

    public static function mutationColumn(string $type, string $entityType): ?string
    {
        if (! isset(AssistantOperationsNativeFileMetadata::definitions()[$type])) { return null; }
        if ($entityType === $type) { return $type === 'warehouse_item_gallery' ? 'native_parent.id' : 'native_source.id'; }
        return match ($entityType) {
            'quality_defect' => $type === 'quality_defect_photo' ? 'native_source.quality_defect_id' : null,
            'workforce_employee' => $type === 'safety_medical_exam' ? 'native_source.employee_id' : null,
            'file' => $type === 'warehouse_item_gallery' ? 'native_source.id' : ($type === 'safety_medical_exam' ? 'native_file.id' : null),
            'warehouse' => $type === 'warehouse_item_gallery' ? 'native_parent.warehouse_id' : null,
            'material' => $type === 'warehouse_item_gallery' ? 'native_parent.material_id' : null,
            default => null,
        };
    }
}
