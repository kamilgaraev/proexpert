<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\DesignManagement\Enums\DesignPackageStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Support\DesignPackageWorkflow;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DesignPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DesignPackage $package */
        $package = $this->resource;
        $status = $this->enumValue($package->status);
        $currentVersion = DesignPackageWorkflow::currentVersion($package);
        $derivative = DesignPackageWorkflow::preferredDerivative($currentVersion);
        $problemFlags = DesignPackageWorkflow::problemFlags($package, $currentVersion, $derivative);
        $artifactsCount = $package->relationLoaded('artifacts') ? $package->artifacts->count() : 0;
        $models = $package->relationLoaded('artifacts')
            ? $package->artifacts->filter(fn ($artifact): bool => $this->enumValue($artifact->artifact_type) === 'model')->values()
            : null;
        $documents = $package->relationLoaded('artifacts')
            ? $package->artifacts->filter(fn ($artifact): bool => $this->enumValue($artifact->artifact_type) !== 'model')->values()
            : null;
        $availableActions = DesignPackageWorkflow::availableActions($package);

        return [
            'id' => $package->id,
            'organization_id' => $package->organization_id,
            'project_id' => $package->project_id,
            'title' => $package->title,
            'stage' => $package->stage,
            'project_stage' => $this->enumValue($package->project_stage),
            'project_stage_label' => trans_message('design_management.project_stages.' . $this->enumValue($package->project_stage)),
            'object_type' => $this->enumValue($package->object_type),
            'object_type_label' => trans_message('design_management.object_types.' . $this->enumValue($package->object_type)),
            'normative_profile_code' => $package->normative_profile_code,
            'discipline' => $package->discipline,
            'status' => $status,
            'status_label' => trans_message("design_management.statuses.packages.{$status}"),
            'planned_issue_date' => $package->planned_issue_date?->format('Y-m-d'),
            'issued_at' => $package->issued_at?->toIso8601String(),
            'issued_by' => $package->issued_by,
            'metadata' => $package->metadata ?? [],
            'project' => $this->whenLoaded('project', fn () => $package->project ? [
                'id' => $package->project->id,
                'name' => $package->project->name,
            ] : null),
            'models_count' => $models?->count(),
            'documents_count' => $documents?->count(),
            'models' => $models !== null ? DesignArtifactResource::collection($models) : null,
            'documents' => $documents !== null ? DesignArtifactResource::collection($documents) : null,
            'artifacts' => DesignArtifactResource::collection($this->whenLoaded('artifacts')),
            'sections' => DesignPackageSectionResource::collection($this->whenLoaded('sections')),
            'review_comments' => DesignReviewCommentResource::collection($this->whenLoaded('reviewComments')),
            'workflow_events' => DesignWorkflowEventResource::collection($this->whenLoaded('workflowEvents')),
            'latest_completeness_check' => new DesignCompletenessCheckResource($this->whenLoaded('latestCompletenessCheck')),
            'current_version' => $currentVersion ? new DesignArtifactVersionResource($currentVersion) : null,
            'derivative' => $derivative ? new DesignModelDerivativeResource($derivative) : $this->missingDerivative(),
            'problem_flags' => $problemFlags,
            'problem_flag_details' => DesignPackageWorkflow::problemFlagDetails($problemFlags),
            'available_actions' => $availableActions,
            'available_action_details' => DesignPackageWorkflow::actionDetails($availableActions),
            'workflow_history' => DesignPackageWorkflow::workflowHistory($package),
            'workflow_summary' => DesignPackageWorkflow::workflowSummary(
                $package,
                $currentVersion,
                $derivative,
                $problemFlags,
                $artifactsCount
            ),
            'created_at' => $package->created_at?->toIso8601String(),
            'updated_at' => $package->updated_at?->toIso8601String(),
        ];
    }

    private function missingDerivative(): array
    {
        return [
            'id' => null,
            'status' => 'missing',
            'viewer_provider' => 'thatopen',
            'derivative_format' => 'thatopen_frag',
        ];
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? $value->value : (string) ($value ?? DesignPackageStatusEnum::DRAFT->value);
    }
}
