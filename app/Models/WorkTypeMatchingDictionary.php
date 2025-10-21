<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkTypeMatchingDictionary extends Model
{
    use HasFactory;

    protected $table = 'work_type_matching_dictionary';

    protected $fillable = [
        'organization_id',
        'imported_text',
        'normalized_text',
        'work_type_id',
        'matched_by_user_id',
        'match_confidence',
        'usage_count',
        'is_confirmed',
    ];

    protected $casts = [
        'match_confidence' => 'decimal:2',
        'usage_count' => 'integer',
        'is_confirmed' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by_user_id');
    }

    public function scopeByOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeConfirmed($query)
    {
        return $query->where('is_confirmed', true);
    }

    public function scopeByNormalizedText($query, string $text)
    {
        return $query->where('normalized_text', $text);
    }

    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    public function confirm(): void
    {
        $this->update(['is_confirmed' => true]);
    }

    public function isConfirmed(): bool
    {
        return $this->is_confirmed;
    }
}

