<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DesignSourceLink extends Model
{
    protected $fillable = ['organization_id', 'project_id', 'source_version_id', 'source_sheet_id', 'source_element_id', 'source_snapshot', 'target_type', 'target_id', 'target_snapshot', 'created_by'];

    protected $casts = ['source_snapshot' => 'array', 'target_snapshot' => 'array', 'ended_at' => 'datetime'];

    protected $attributes = ['status' => 'active', 'row_version' => 1];

    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(DesignArtifactVersion::class, 'source_version_id');
    }

    public function sourceSheet(): BelongsTo
    {
        return $this->belongsTo(DesignDocumentSheet::class, 'source_sheet_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
