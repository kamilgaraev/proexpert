<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'work_type_category',
        'template_structure',
        'is_public',
        'usage_count',
        'created_by_user_id',
    ];

    protected $casts = [
        'template_structure' => 'array',
        'is_public' => 'boolean',
        'usage_count' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopePrivate($query)
    {
        return $query->where('is_public', false);
    }

    public function scopeByOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('work_type_category', $category);
    }

    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    public function isPublic(): bool
    {
        return $this->is_public;
    }
}

