<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class AcceptanceChecklist extends Model
{
    use SoftDeletes;

    protected $fillable = ['organization_id', 'project_id', 'acceptance_scope_id', 'title', 'status'];

    public function scope(): BelongsTo
    {
        return $this->belongsTo(AcceptanceScope::class, 'acceptance_scope_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AcceptanceChecklistItem::class);
    }
}
