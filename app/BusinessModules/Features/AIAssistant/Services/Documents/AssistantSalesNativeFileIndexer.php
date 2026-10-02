<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\Jobs\ProcessAssistantDocument;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class AssistantSalesNativeFileIndexer
{
    public function __construct(private readonly AssistantSalesNativeFileAdapter $native) {}

    public function prepare(int $organizationId, ?int $projectId = null, ?string $entityType = null, string|int|null $entityId = null, ?callable $heartbeat = null): void
    {
        if ($organizationId < 1 || (($entityType === null) !== ($entityId === null))) { return; }
        foreach (AssistantSalesNativeFileMetadata::types() as $type) {
            $column = $entityType === null ? null : self::mutationColumn($type, $entityType);
            if ($entityType !== null && $column === null) { continue; }
            $query = AssistantSalesNativeFileMetadata::sourceQuery($type, $organizationId);
            $project = AssistantSalesNativeFileMetadata::versionExpressions($type)['parent_project_id'] ?? null;
            if ($project === null && $projectId !== null) { continue; }
            if ($project !== null) {
                $projects = Project::query()->where(static fn (Builder $scope): Builder => $scope->where('organization_id', $organizationId)
                    ->orWhereIn('id', DB::table('project_organization')->where('organization_id', $organizationId)->where('is_active', true)->select('project_id')))->select('id');
                $query->where(static fn (QueryBuilder $scope): QueryBuilder => $scope->whereRaw($project.' IS NULL')->orWhereIn(DB::raw($project), $projects));
                if ($projectId !== null) { $query->whereRaw($project.' = ?', [$projectId]); }
            }
            if ($column !== null) { $query->where($column, $entityId); }
            foreach ($query->select('native_source.id')->reorder()->lazyById(50, 'native_source.id', 'id') as $row) {
                $document = $this->native->mapForIndexing($organizationId, (string) $row->id, $type);
                if ($document !== null && $document->status === AIAssistantDocument::STATUS_QUEUED) {
                    $documentId = (int) $document->id;
                    DB::afterCommit(static fn () => ProcessAssistantDocument::dispatch($documentId));
                }
                if ($heartbeat !== null) { $heartbeat(); }
            }
        }
    }

    public static function mutationColumn(string $type, string $entityType): ?string
    {
        if (! isset(AssistantSalesNativeFileMetadata::definitions()[$type])) { return null; }
        if ($entityType === $type) { return 'native_source.id'; }
        return match ($entityType) {
            'commercial_proposal' => str_starts_with($type, 'commercial_proposal_') ? 'native_source.commercial_proposal_id' : null,
            'commercial_proposal_version' => str_starts_with($type, 'commercial_proposal_') ? 'native_source.commercial_proposal_version_id' : null,
            'purchase_order' => $type === 'purchase_receipt_document' ? 'native_source.purchase_order_id' : null,
            'purchase_receipt' => $type === 'purchase_receipt_document' ? 'native_source.purchase_receipt_id' : null,
            default => null,
        };
    }
}
