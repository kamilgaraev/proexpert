<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support\Rules;

use App\BusinessModules\Features\DesignManagement\Enums\DesignCompletenessStatusEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignReviewCommentSeverityEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignReviewCommentStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewComment;
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

        return ($package->reviewComments ?? collect())
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
            ))
            ->values()
            ->all();
    }
}
