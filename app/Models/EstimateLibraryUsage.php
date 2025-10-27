<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateLibraryUsage extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'library_item_id',
        'estimate_id',
        'user_id',
        'applied_parameters',
        'positions_added',
        'used_at',
        'metadata',
    ];

    protected $casts = [
        'applied_parameters' => 'array',
        'used_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function libraryItem(): BelongsTo
    {
        return $this->belongsTo(EstimateLibraryItem::class, 'library_item_id');
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeByLibraryItem($query, int $libraryItemId)
    {
        return $query->where('library_item_id', $libraryItemId);
    }

    public function scopeByEstimate($query, int $estimateId)
    {
        return $query->where('estimate_id', $estimateId);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('used_at', '>=', now()->subDays($days));
    }
}
