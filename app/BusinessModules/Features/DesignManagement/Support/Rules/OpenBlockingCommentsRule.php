<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support\Rules;

use App\BusinessModules\Features\DesignManagement\Enums\DesignCompletenessStatusEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignReviewCommentSeverityEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignReviewCommentStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewComment;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewCommentIssueMapping;
use App\BusinessModules\Features\DesignManagement\Services\DesignPackageBlockingIssueQuery;
use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRule;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRuleResult;
use BackedEnum;

final class OpenBlockingCommentsRule implements DesignCompletenessRule
{
    public function check(DesignPackage $package): array
    {
        $closedStatuses = [
            DesignReviewCommentStatusEnum::RESOLVED->value,
            DesignReviewCommentStatusEnum::ACCEPTED->value,
        ];

        $canonical = DesignPackageBlockingIssueQuery::forPackage($package)
            ->where('metadata->blocking->active', true)
            ->where('status', '!=', QualityDefectStatusEnum::RESOLVED->value)
            ->get()
            ->map(static fn (QualityDefect $issue): DesignCompletenessRuleResult => new DesignCompletenessRuleResult(
                'open_blocking_comments',
                DesignCompletenessStatusEnum::BLOCKED,
                trans_message('design_management.completeness.open_blocking_comment'),
                'quality_defect',
                (int) $issue->id
            ));
        $mappedIds = DesignReviewCommentIssueMapping::query()
            ->where('organization_id', $package->organization_id)
            ->where('project_id', $package->project_id)
            ->whereNotNull('legacy_design_review_comment_id')
            ->pluck('legacy_design_review_comment_id');

        return $canonical->concat(($package->reviewComments ?? collect())
            ->whereNotIn('id', $mappedIds)
            ->filter(static function (DesignReviewComment $comment) use ($closedStatuses): bool {
                $severity = $comment->severity instanceof BackedEnum ? $comment->severity->value : (string) $comment->severity;
                $status = $comment->status instanceof BackedEnum ? $comment->status->value : (string) $comment->status;

                return $severity === DesignReviewCommentSeverityEnum::BLOCKING->value
                    && !in_array($status, $closedStatuses, true);
            })
            ->map(static fn (DesignReviewComment $comment): DesignCompletenessRuleResult => new DesignCompletenessRuleResult(
                'open_blocking_comments',
                DesignCompletenessStatusEnum::BLOCKED,
                trans_message('design_management.completeness.open_blocking_comment'),
                'review_comment',
                (int) $comment->id
            )))
            ->values()
            ->all();
    }
}
