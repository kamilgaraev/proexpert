<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use App\BusinessModules\Features\DesignManagement\Enums\DesignReviewCommentSeverityEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignReviewCommentStatusEnum;
use App\BusinessModules\Features\DesignManagement\Services\DesignCompletenessService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DesignReviewComment extends Model
{
    protected $fillable = [
        'organization_id',
        'project_id',
        'package_id',
        'round_id',
        'section_id',
        'artifact_id',
        'version_id',
        'sheet_id',
        'author_id',
        'assignee_id',
        'resolved_by',
        'severity',
        'status',
        'body',
        'response',
        'bim_element_id',
        'due_date',
        'resolved_at',
        'metadata',
    ];

    protected $casts = [
        'severity' => DesignReviewCommentSeverityEnum::class,
        'status' => DesignReviewCommentStatusEnum::class,
        'due_date' => 'date',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'severity' => 'warning',
        'status' => 'open',
        'metadata' => '{}',
    ];

    protected static function booted(): void
    {
        $invalidate = static function (self $comment): void {
            $scopes = [
                [(int) $comment->package_id, (int) $comment->organization_id, (int) $comment->project_id],
                [(int) $comment->getOriginal('package_id'), (int) $comment->getOriginal('organization_id'), (int) $comment->getOriginal('project_id')],
            ];
            usort($scopes, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
            $seen = [];
            foreach ($scopes as [$packageId, $organizationId, $projectId]) {
                $key = $packageId . ':' . $organizationId . ':' . $projectId;
                if ($packageId === 0 || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $package = DesignPackage::query()->whereKey($packageId)
                    ->where('organization_id', $organizationId)->where('project_id', $projectId)->first();
                if ($package !== null) {
                    app(DesignCompletenessService::class)->invalidateLockedInputs($package);
                }
            }
        };
        static::saved($invalidate);
        static::deleted($invalidate);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(DesignPackage::class, 'package_id');
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(DesignReviewRound::class, 'round_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(DesignPackageSection::class, 'section_id');
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(DesignArtifact::class, 'artifact_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DesignArtifactVersion::class, 'version_id');
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(DesignDocumentSheet::class, 'sheet_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
