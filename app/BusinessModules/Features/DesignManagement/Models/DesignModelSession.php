<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DesignModelSession extends Model
{
    protected $fillable = ['organization_id', 'project_id', 'model_set_id', 'model_set_revision_id', 'created_by', 'title'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function modelSet(): BelongsTo
    {
        return $this->belongsTo(DesignModelSet::class, 'model_set_id');
    }

    public function modelSetRevision(): BelongsTo
    {
        return $this->belongsTo(DesignModelSetRevision::class, 'model_set_revision_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
