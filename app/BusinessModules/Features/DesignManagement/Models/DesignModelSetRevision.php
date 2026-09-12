<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DesignModelSetRevision extends Model
{
    protected $fillable = ['model_set_id', 'revision', 'version_ids', 'transforms', 'created_by'];

    protected $casts = ['revision' => 'integer', 'version_ids' => 'array', 'transforms' => 'array'];

    public function modelSet(): BelongsTo
    {
        return $this->belongsTo(DesignModelSet::class, 'model_set_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
