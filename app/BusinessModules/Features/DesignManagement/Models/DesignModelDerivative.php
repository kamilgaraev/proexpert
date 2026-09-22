<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use App\BusinessModules\Features\DesignManagement\Enums\DesignDerivativeStatusEnum;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DesignModelDerivative extends Model
{
    protected $fillable = [
        'organization_id',
        'project_id',
        'version_id',
        'created_by',
        'updated_by',
        'prepared_by',
        'viewer_provider',
        'derivative_format',
        'derivative_file_path',
        'status',
        'progress_percent',
        'processing_stage',
        'prepared_at',
        'processing_started_at',
        'processing_finished_at',
        'failed_reason',
        'metadata',
    ];

    protected $casts = [
        'status' => DesignDerivativeStatusEnum::class,
        'progress_percent' => 'integer',
        'prepared_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'processing_finished_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'viewer_provider' => 'thatopen',
        'derivative_format' => 'thatopen_frag',
        'status' => 'missing',
        'progress_percent' => 0,
        'metadata' => '{}',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DesignArtifactVersion::class, 'version_id');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeForResponse(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        $metadata = $table.'.metadata';

        return $query->select([
            $table.'.id',
            $table.'.organization_id',
            $table.'.project_id',
            $table.'.version_id',
            $table.'.created_by',
            $table.'.updated_by',
            $table.'.prepared_by',
            $table.'.viewer_provider',
            $table.'.derivative_format',
            $table.'.derivative_file_path',
            $table.'.status',
            $table.'.progress_percent',
            $table.'.processing_stage',
            $table.'.prepared_at',
            $table.'.processing_started_at',
            $table.'.processing_finished_at',
            $table.'.failed_reason',
            $table.'.created_at',
            $table.'.updated_at',
        ])->selectRaw(
            "CASE WHEN {$metadata} IS NULL THEN '{}'::jsonb WHEN jsonb_typeof({$metadata}->'ifc_metadata') = 'object' THEN jsonb_set({$metadata} - 'coordinate_transformations', '{ifc_metadata}', ({$metadata}->'ifc_metadata') - 'transformations') ELSE {$metadata} - 'coordinate_transformations' END as metadata"
        );
    }
}
