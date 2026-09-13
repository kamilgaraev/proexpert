<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DesignImpactReview extends Model
{
    protected $fillable = ['organization_id', 'project_id', 'link_id', 'previous_version_id', 'new_version_id', 'status', 'decision', 'reason', 'decided_by', 'decided_at'];

    protected $casts = ['decided_at' => 'datetime'];

    public function link(): BelongsTo
    {
        return $this->belongsTo(DesignSourceLink::class, 'link_id');
    }

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(DesignArtifactVersion::class, 'previous_version_id');
    }

    public function newVersion(): BelongsTo
    {
        return $this->belongsTo(DesignArtifactVersion::class, 'new_version_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
