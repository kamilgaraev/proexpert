<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewComment;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewCommentIssueMapping;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class DesignPackageBlockingIssueQuery
{
    public static function forPackage(DesignPackage $package): Builder
    {
        return self::scopedIssues($package);
    }

    public static function withOpenBlockingCount(Builder $packages): Builder
    {
        $canonical = self::scopedIssues(null)
            ->where('metadata->blocking->active', true)
            ->where('status', '!=', 'resolved')
            ->selectRaw('count(*)');
        $legacy = DesignReviewComment::query()
            ->whereColumn('design_review_comments.organization_id', 'design_packages.organization_id')
            ->whereColumn('design_review_comments.project_id', 'design_packages.project_id')
            ->whereColumn('design_review_comments.package_id', 'design_packages.id')
            ->where('severity', 'blocking')
            ->whereNotIn('status', ['resolved', 'accepted'])
            ->whereNotExists(DesignReviewCommentIssueMapping::query()
                ->selectRaw('1')
                ->whereColumn('organization_id', 'design_review_comments.organization_id')
                ->whereColumn('project_id', 'design_review_comments.project_id')
                ->whereColumn('legacy_design_review_comment_id', 'design_review_comments.id'))
            ->selectRaw('count(*)');

        return $packages->addSelect(['open_blocking_comments_count' => DB::query()->selectRaw(
            '('.$canonical->toSql().') + ('.$legacy->toSql().')',
            [...$canonical->getBindings(), ...$legacy->getBindings()]
        )]);
    }

    private static function scopedIssues(?DesignPackage $package): Builder
    {
        return QualityDefect::query()
            ->when($package !== null,
                fn (Builder $query) => $query->where('organization_id', $package->organization_id)->where('project_id', $package->project_id),
                fn (Builder $query) => $query->whereColumn('quality_defects.organization_id', 'design_packages.organization_id')->whereColumn('quality_defects.project_id', 'design_packages.project_id'))
            ->where('kind', 'project')
            ->where(function (Builder $query) use ($package): void {
                $query->when($package !== null,
                    fn (Builder $context) => $context->where('metadata->design_issue_context->package_id', $package->id),
                    fn (Builder $context) => $context->whereRaw("quality_defects.metadata #>> '{design_issue_context,package_id}' = design_packages.id::text"))
                    ->orWhereExists(function ($versions) use ($package): void {
                        $versions->selectRaw('1')
                            ->from('design_artifact_versions as issue_version')
                            ->join('design_artifacts as issue_artifact', 'issue_artifact.id', '=', 'issue_version.artifact_id')
                            ->when($package !== null,
                                fn ($artifacts) => $artifacts->where('issue_artifact.package_id', $package->id)->where('issue_artifact.organization_id', $package->organization_id)->where('issue_artifact.project_id', $package->project_id),
                                fn ($artifacts) => $artifacts->whereColumn('issue_artifact.package_id', 'design_packages.id')->whereColumn('issue_artifact.organization_id', 'design_packages.organization_id')->whereColumn('issue_artifact.project_id', 'design_packages.project_id'))
                            ->where(function ($context): void {
                                $context->whereRaw("quality_defects.metadata #>> '{design_issue_context,version_id}' = issue_version.id::text")
                                    ->orWhereRaw("quality_defects.metadata #> '{design_issue_context,elements}' @> jsonb_build_array(jsonb_build_object('version_id', issue_version.id))")
                                    ->orWhere(function ($view): void {
                                        $view->whereRaw("quality_defects.metadata #> '{design_issue_context,view_models}' @> jsonb_build_array(jsonb_build_object('version_id', issue_version.id))")
                                            ->whereRaw("COALESCE(quality_defects.metadata #> '{design_issue_context,elements}', '[]'::jsonb) = '[]'::jsonb");
                                    })
                                    ->orWhereExists(function ($sets): void {
                                        $sets->selectRaw('1')->from('design_model_set_revisions as issue_set_revision')
                                            ->join('design_model_sets as issue_set', 'issue_set.id', '=', 'issue_set_revision.model_set_id')
                                            ->whereColumn('issue_set.organization_id', 'quality_defects.organization_id')
                                            ->whereColumn('issue_set.project_id', 'quality_defects.project_id')
                                            ->whereRaw("quality_defects.metadata #>> '{design_issue_context,model_set_revision_id}' = issue_set_revision.id::text")
                                            ->whereRaw('issue_set_revision.version_ids::jsonb @> jsonb_build_array(issue_version.id)')
                                            ->whereRaw("COALESCE(quality_defects.metadata #> '{design_issue_context,elements}', '[]'::jsonb) = '[]'::jsonb");
                                    });
                            });
                    });
            });
    }
}
