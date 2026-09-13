<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DesignCompositionExclusion extends Model
{
    protected $fillable = ['organization_id', 'project_id', 'package_id', 'revision_id', 'item_key', 'reason', 'created_by'];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(DesignCompositionRevision::class, 'revision_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
