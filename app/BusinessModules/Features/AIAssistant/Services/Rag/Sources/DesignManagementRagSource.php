<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSet;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewComment;
use Illuminate\Database\Eloquent\Builder;

final class DesignManagementRagSource extends ModelDomainRagSource
{
    public function sourceType(): string { return 'design'; }

    public function entities(): array
    {
        return [
            'design_package' => ['model' => DesignPackage::class, 'fields' => ['id','project_id','title','stage','project_stage','discipline','status','planned_issue_date','issued_at']],
            'design_artifact' => ['model' => DesignArtifact::class, 'fields' => ['id','project_id','package_id','title','document_title','document_code','artifact_type','discipline','stage','status']],
            'design_artifact_version' => ['model' => DesignArtifactVersion::class, 'fields' => ['id','project_id','artifact_id','title','version_number','revision_label','source_format','file_format','source_original_name','status','model_date','is_current']],
            'design_review_comment' => ['model' => DesignReviewComment::class, 'fields' => ['id','project_id','package_id','artifact_id','body','response','severity','status','due_date','resolved_at','author_id','assignee_id']],
            'design_model_set' => ['model' => DesignModelSet::class, 'fields' => ['id','project_id','title','revision']],
        ];
    }

    protected function query(string $class, int $organizationId, ?int $projectId): Builder
    {
        $query = parent::query($class, $organizationId, $projectId);
        $table = $query->getModel()->getTable();
        $query->whereHas('project', static fn (Builder $project): Builder => $project->where('organization_id', $organizationId));
        if ($class === DesignArtifact::class || $class === DesignReviewComment::class) {
            $query->whereHas('package', static fn (Builder $package): Builder => $package->where('organization_id', $organizationId)
                ->whereColumn('design_packages.project_id', $table.'.project_id'));
        }
        if ($class === DesignArtifactVersion::class) {
            $query->whereHas('artifact', fn (Builder $artifact): Builder => $artifact->whereIn('design_artifacts.id',
                $this->query(DesignArtifact::class, $organizationId, $projectId)->select('design_artifacts.id'))
                ->whereColumn('design_artifacts.project_id', $table.'.project_id'));
        }
        if ($class === DesignReviewComment::class) {
            foreach (['artifact_id' => DesignArtifact::class, 'version_id' => DesignArtifactVersion::class] as $column => $parentClass) {
                $parent = $this->query($parentClass, $organizationId, $projectId);
                $parentTable = $parent->getModel()->getTable();
                $parent->whereRaw($parentTable.'.project_id IS NOT DISTINCT FROM '.$table.'.project_id');
                $query->where(static fn (Builder $linked): Builder => $linked->whereNull($table.'.'.$column)
                    ->orWhereIn($table.'.'.$column, $parent->select($parentTable.'.id')));
            }
        }

        return $query;
    }
}
