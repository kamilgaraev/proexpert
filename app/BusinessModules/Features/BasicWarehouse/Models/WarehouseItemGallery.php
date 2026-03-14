<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Models;

use App\Models\File;
use App\Models\Material;
use App\Models\Organization;
use App\Services\Storage\FileService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class WarehouseItemGallery extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'material_id',
    ];

    protected $appends = [
        'photo_gallery',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(OrganizationWarehouse::class, 'warehouse_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    public function photos(): MorphMany
    {
        return $this->files()->where('type', 'photo')->orderByDesc('created_at');
    }

    public function getPhotoGalleryAttribute(): array
    {
        $photos = $this->relationLoaded('photos') ? $this->getRelation('photos') : $this->photos()->get();
        $fileService = app(FileService::class);

        return $photos->map(static fn (File $file): array => [
            'id' => $file->id,
            'name' => $file->name,
            'original_name' => $file->original_name,
            'url' => $fileService->temporaryUrl($file->path, 60) ?? $file->url,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'category' => $file->category,
            'uploaded_at' => optional($file->created_at)?->toDateTimeString(),
        ])->values()->all();
    }
}
