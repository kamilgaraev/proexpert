<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use Illuminate\Database\Eloquent\Model;

final class DesignReviewCommentIssueMapping extends Model
{
    protected $fillable = [
        'organization_id',
        'project_id',
        'legacy_api_id',
        'legacy_design_review_comment_id',
        'quality_defect_id',
    ];
}
