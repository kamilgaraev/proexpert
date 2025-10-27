<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateLibrary extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'created_by_user_id',
        'name',
        'description',
        'category',
        'access_level',
        'tags',
        'usage_count',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EstimateLibraryItem::class, 'library_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeAccessibleBy($query, int $organizationId)
    {
        return $query->where(function ($q) use ($organizationId) {
            $q->where('organization_id', $organizationId)
              ->orWhere('access_level', 'public');
        });
    }

    public function scopePublic($query)
    {
        return $query->where('access_level', 'public');
    }

    public function scopePrivate($query)
    {
        return $query->where('access_level', 'private');
    }

    public function scopeSearch($query, string $searchTerm)
    {
        return $query->whereRaw(
            "search_vector @@ plainto_tsquery('russian', ?)",
            [$searchTerm]
        );
    }

    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    public function isAccessibleBy(int $organizationId): bool
    {
        return $this->organization_id === $organizationId || $this->access_level === 'public';
    }

    public function isPublic(): bool
    {
        return $this->access_level === 'public';
    }

    public function getItemsCountAttribute(): int
    {
        return $this->items()->count();
    }
}
