<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Resources;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AcceptanceScope */
final class AcceptanceScopeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $scope = $this->resource;

        if (!$scope instanceof AcceptanceScope) {
            return [];
        }

        $actions = match ($scope->status) {
            'planned', 'reopened', 'rejected' => ['start'],
            'in_progress' => ['create_finding', 'accept', 'reject'],
            'findings_open' => ['resolve_findings', 'ready_for_reinspection', 'reject'],
            'ready_for_reinspection' => ['accept', 'reject'],
            'accepted' => ['handover', 'reopen'],
            'handed_over' => ['reopen'],
            default => [],
        };

        return [
            'id' => $scope->id,
            'organization_id' => $scope->organization_id,
            'project_id' => $scope->project_id,
            'project_location_id' => $scope->project_location_id,
            'title' => $scope->title,
            'description' => $scope->description,
            'status' => $scope->status,
            'planned_acceptance_date' => $scope->planned_acceptance_date?->format('Y-m-d'),
            'accepted_at' => $scope->accepted_at?->toIso8601String(),
            'handed_over_at' => $scope->handed_over_at?->toIso8601String(),
            'reopened_at' => $scope->reopened_at?->toIso8601String(),
            'workflow_summary' => [
                'status' => $scope->status,
                'available_actions' => $actions,
                'problem_flags' => $this->problemFlags($scope),
            ],
            'project' => $scope->relationLoaded('project') && $scope->project ? [
                'id' => $scope->project->id,
                'name' => $scope->project->name,
            ] : null,
            'location' => $scope->relationLoaded('location') && $scope->location ? new ProjectLocationResource($scope->location) : null,
            'checklists' => $scope->relationLoaded('checklists') ? AcceptanceChecklistResource::collection($scope->checklists) : [],
            'sessions' => $scope->relationLoaded('sessions') ? AcceptanceSessionResource::collection($scope->sessions) : [],
            'findings' => $scope->relationLoaded('findings') ? AcceptanceFindingResource::collection($scope->findings) : [],
            'handover_package' => $scope->relationLoaded('handoverPackage') && $scope->handoverPackage ? new HandoverPackageResource($scope->handoverPackage) : null,
        ];
    }

    private function problemFlags(AcceptanceScope $scope): array
    {
        $flags = [];
        $openFindings = $scope->relationLoaded('findings')
            ? $scope->findings->where('status', 'open')->count()
            : 0;

        if ($openFindings > 0) {
            $flags[] = [
                'key' => 'open_findings',
                'severity' => 'warning',
                'label' => trans_message('handover_acceptance.problem_flags.open_findings'),
                'count' => $openFindings,
            ];
        }

        $package = $scope->relationLoaded('handoverPackage') ? $scope->handoverPackage : null;
        $missingDocuments = $package && $package->relationLoaded('documents')
            ? $package->documents->where('is_required', true)->where('status', '!=', 'approved')->count()
            : 0;

        if ($missingDocuments > 0) {
            $flags[] = [
                'key' => 'required_documents_missing',
                'severity' => 'warning',
                'label' => trans_message('handover_acceptance.problem_flags.required_documents_missing'),
                'count' => $missingDocuments,
            ];
        }

        return $flags;
    }
}
