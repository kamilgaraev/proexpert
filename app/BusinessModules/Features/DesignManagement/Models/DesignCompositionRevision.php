<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DesignCompositionRevision extends Model
{
    protected $fillable = ['organization_id', 'project_id', 'package_id', 'revision_number', 'status', 'composition', 'fingerprint', 'created_by', 'approved_by', 'approved_at', 'needs_review_reason'];

    protected $casts = ['composition' => 'array', 'approved_at' => 'datetime'];

    public function package(): BelongsTo
    {
        return $this->belongsTo(DesignPackage::class, 'package_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function exclusions(): HasMany
    {
        return $this->hasMany(DesignCompositionExclusion::class, 'revision_id')->orderByDesc('id');
    }
}
