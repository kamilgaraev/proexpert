<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NormativeSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'collection_id',
        'parent_id',
        'code',
        'name',
        'description',
        'path',
        'level',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'level' => 'integer',
        'metadata' => 'array',
    ];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(NormativeCollection::class, 'collection_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(NormativeSection::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(NormativeSection::class, 'parent_id')->orderBy('sort_order');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(NormativeRate::class, 'section_id');
    }

    public function scopeByCollection($query, int $collectionId)
    {
        return $query->where('collection_id', $collectionId);
    }

    public function scopeRootLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeByLevel($query, int $level)
    {
        return $query->where('level', $level);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('code');
    }

    public function getFullPathAttribute(): string
    {
        if (!$this->path) {
            return $this->name;
        }
        
        $pathIds = explode('/', trim($this->path, '/'));
        $sections = self::whereIn('id', $pathIds)->orderByRaw('array_position(ARRAY[' . implode(',', $pathIds) . '], id)')->get();
        
        return $sections->pluck('name')->implode(' > ');
    }

    public function isLeaf(): bool
    {
        return $this->children()->count() === 0;
    }

    public function hasChildren(): bool
    {
        return $this->children()->exists();
    }

    public function updatePath(): void
    {
        $path = '/';
        if ($this->parent_id) {
            $parent = $this->parent;
            $path = rtrim($parent->path ?: '/', '/') . '/' . $parent->id . '/';
        }
        
        $this->path = $path;
        $this->save();
    }
}

