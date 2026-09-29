<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\Jobs\ProcessAssistantDocument;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class AssistantWorkforceNativeFileIndexer
{
    public function __construct(private readonly AssistantNativeFileAdapter $native, private readonly AssistantDocumentService $documents) {}

    public function prepare(int $organizationId, ?int $projectId = null, string|int|null $entityId = null, ?callable $heartbeat = null): void
    {
        $query = DB::table('workforce_export_package_files as source')
            ->join('workforce_export_packages as package', 'package.id', '=', 'source.export_package_id')
            ->join('workforce_payroll_periods as period', 'period.id', '=', 'package.payroll_period_id')
            ->where('source.organization_id', $organizationId)->where('package.organization_id', $organizationId)
            ->where('period.organization_id', $organizationId)->where('source.storage_disk', 's3');
        $projects = Project::query()->where(static fn (Builder $scope): Builder => $scope->where('organization_id', $organizationId)
            ->orWhereIn('id', DB::table('project_organization')->where('organization_id', $organizationId)->select('project_id')))->select('id');
        $query->where(static fn ($scope) => $scope->whereNull('period.project_id')->orWhereIn('period.project_id', $projects));
        if ($projectId !== null) { $query->where('period.project_id', $projectId); }
        if ($entityId !== null) { $query->where('source.id', $entityId); }
        foreach ($query->select('source.id')->lazyById(50, 'source.id', 'id') as $row) {
            $file = $this->native->mapForIndexing($organizationId, (int) $row->id);
            if ($file === null) {
                if ($heartbeat !== null) { $heartbeat(); }
                continue;
            }
            $document = $this->documents->registerFile($file);
            if ($document->status === AIAssistantDocument::STATUS_QUEUED) {
                ProcessAssistantDocument::dispatch((int) $document->id);
            }
            if ($heartbeat !== null) { $heartbeat(); }
        }
    }

}
