<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Tenders\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TenderRequirement extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'tender_id',
        'kind',
        'title',
        'description',
        'is_required',
        'required_for_status',
        'status',
        'owner_user_id',
        'due_at',
        'completed_at',
        'metadata',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
