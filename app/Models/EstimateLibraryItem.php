<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateLibraryItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'library_id',
        'name',
        'description',
        'parameters',
        'calculation_rules',
        'positions_count',
        'usage_count',
        'metadata',
    ];

    protected $casts = [
        'parameters' => 'array',
        'metadata' => 'array',
    ];

    public function library(): BelongsTo
    {
        return $this->belongsTo(EstimateLibrary::class, 'library_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(EstimateLibraryItemPosition::class, 'library_item_id')->orderBy('sort_order');
    }

    public function usageHistory(): HasMany
    {
        return $this->hasMany(EstimateLibraryUsage::class, 'library_item_id');
    }

    public function scopeByLibrary($query, int $libraryId)
    {
        return $query->where('library_id', $libraryId);
    }

    public function incrementUsage(): void
    {
        $this->increment('usage_count');
        $this->library->incrementUsage();
    }

    public function hasParameters(): bool
    {
        return !empty($this->parameters);
    }

    public function getRequiredParameters(): array
    {
        if (!$this->hasParameters()) {
            return [];
        }

        return array_filter($this->parameters, function ($param) {
            return isset($param['required']) && $param['required'] === true;
        });
    }

    public function validateParameters(array $providedParams): bool
    {
        $requiredParams = $this->getRequiredParameters();
        
        foreach ($requiredParams as $key => $param) {
            if (!isset($providedParams[$key])) {
                return false;
            }
        }
        
        return true;
    }
}
