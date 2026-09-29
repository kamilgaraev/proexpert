<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\Jobs\ProcessAssistantDocument;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class AssistantLegalNativeFileIndexer
{
    public function __construct(private readonly AssistantLegalNativeFileAdapter $native,private readonly AssistantDocumentService $documents) {}

    public function prepare(int $organizationId,?int $projectId = null,?string $entityType = null,string|int|null $entityId = null,?callable $heartbeat = null): void
    {
        foreach (AssistantLegalNativeFileMetadata::types() as $type) {
            $query = AssistantLegalNativeFileMetadata::sourceQuery($type,$organizationId);
            $projectColumn = match ($type) {
                'legal_document_version'=>'native_document.primary_project_id',
                'executive_version'=>'native_document.project_id',
                'executive_approved_list'=>'native_source.project_id',
            };
            $projects = Project::query()->where(static fn (Builder $scope): Builder => $scope->where('organization_id',$organizationId)
                ->orWhereIn('id',DB::table('project_organization')->where('organization_id',$organizationId)->select('project_id')))->select('id');
            $query->where(static fn ($scope) => $scope->whereNull($projectColumn)->orWhereIn($projectColumn,$projects));
            if ($projectId !== null) { $query->where($projectColumn,$projectId); }
            if ($entityType !== null && $entityId !== null) {
                $column = $type === $entityType ? 'native_source.id' : match ($entityType) {
                    'legal_document'=>$type === 'legal_document_version' ? 'native_source.document_id' : null,
                    'legal_document_file'=>$type === 'legal_document_version' ? 'native_source.document_file_id' : null,
                    'executive_document'=>$type === 'executive_version' ? 'native_source.document_id' : null,
                    'executive_document_set'=>$type === 'executive_version' ? 'native_document.document_set_id' : null,
                    default=>null,
                };
                if ($column === null) { continue; }
                $query->where($column,$entityId);
            }
            foreach ($query->select('native_source.id')->lazyById(50,'native_source.id','id') as $row) {
                $file = $this->native->mapForIndexing($organizationId,(int)$row->id,$type);
                if ($file !== null) {
                    $document = $this->documents->registerFile($file);
                    if ($document->status === AIAssistantDocument::STATUS_QUEUED) { ProcessAssistantDocument::dispatch((int)$document->id); }
                }
                if ($heartbeat !== null) { $heartbeat(); }
            }
        }
    }
}
