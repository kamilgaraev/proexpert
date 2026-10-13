<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Enums\DesignProjectStageEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\Models\Project;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class DesignProjectOverviewQuery
{
    public function __construct(private DesignAccessService $access) {}

    public function overview(int $organizationId, int $projectId, ?User $actor = null): array
    {
        $actor = $this->access->requireActor($actor);
        if (! $this->access->canAccessProject($actor, $organizationId, $projectId, 'design-management.view')) {
            throw new DomainException(trans_message('design_management.errors.project_not_found'));
        }
        $project = Project::query()->accessibleByOrganization($organizationId)->whereKey($projectId)->first(['id', 'name']);
        if ($project === null) {
            throw new DomainException(trans_message('design_management.errors.project_not_found'));
        }

        $packages = DesignPackage::query()->forOrganization($organizationId)->where('project_id', $projectId)
            ->select(['design_packages.id', 'design_packages.project_stage', 'design_packages.status'])
            ->withOpenBlockingCount();
        $counts = DB::query()->fromSub($packages, 'project_packages')
            ->selectRaw("project_stage, COUNT(*) AS packages_count,
                COUNT(*) FILTER (WHERE status = 'issued') AS issued_packages_count,
                COUNT(*) FILTER (WHERE status = 'archived') AS archived_packages_count,
                COUNT(*) FILTER (WHERE status NOT IN ('issued', 'archived')) AS open_packages_count,
                COUNT(*) FILTER (WHERE open_blocking_comments_count > 0) AS blocked_packages_count")
            ->groupBy('project_stage')->get()->keyBy('project_stage');
        $fields = ['packages_count', 'issued_packages_count', 'archived_packages_count', 'open_packages_count', 'blocked_packages_count'];
        $totals = array_fill_keys($fields, 0);
        $stages = [];
        foreach ([DesignProjectStageEnum::SURVEY, DesignProjectStageEnum::PD, DesignProjectStageEnum::RD, DesignProjectStageEnum::BIM] as $stage) {
            $row = $counts->get($stage->value);
            $stageCounts = [];
            foreach ($fields as $field) {
                $stageCounts[$field] = (int) ($row?->{$field} ?? 0);
                $totals[$field] += $stageCounts[$field];
            }
            $stages[] = ['project_stage' => $stage->value, 'label' => trans_message('design_management.project_stages.'.$stage->value)] + $stageCounts;
        }

        return ['project' => ['id' => $project->id, 'name' => $project->name], 'totals' => $totals, 'stages' => $stages];
    }
}
