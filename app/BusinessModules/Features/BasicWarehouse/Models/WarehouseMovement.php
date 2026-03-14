<?php

namespace App\BusinessModules\Features\BasicWarehouse\Models;

use App\Models\File;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Модель движения активов на складе
 */
class WarehouseMovement extends Model
{
    use HasFactory;

    protected $appends = [
        'photo_gallery',
    ];

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'material_id',
        'movement_type',
        'quantity',
        'price',
        'from_warehouse_id',
        'to_warehouse_id',
        'project_id',
        'user_id',
        'document_number',
        'reason',
        'metadata',
        'movement_date',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'price' => 'decimal:2',
        'metadata' => 'array',
        'movement_date' => 'datetime',
    ];

    // Типы движений
    const TYPE_RECEIPT = 'receipt';
    const TYPE_WRITE_OFF = 'write_off';
    const TYPE_TRANSFER_IN = 'transfer_in';
    const TYPE_TRANSFER_OUT = 'transfer_out';
    const TYPE_ADJUSTMENT = 'adjustment';
    const TYPE_RETURN = 'return';

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

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(OrganizationWarehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(OrganizationWarehouse::class, 'to_warehouse_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    public function photos(): MorphMany
    {
        return $this->files()->where('type', 'photo')->orderByDesc('created_at');
    }

    /**
     * Scope для фильтрации по типу движения
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('movement_type', $type);
    }

    /**
     * Scope для фильтрации по датам
     */
    public function scopeBetweenDates($query, $dateFrom, $dateTo)
    {
        return $query->whereBetween('movement_date', [$dateFrom, $dateTo]);
    }

    public function getPhotoGalleryAttribute(): array
    {
        $photos = $this->relationLoaded('photos') ? $this->getRelation('photos') : $this->photos()->get();

        return $photos->map(static fn (File $file): array => [
            'id' => $file->id,
            'name' => $file->name,
            'original_name' => $file->original_name,
            'url' => $file->url,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'category' => $file->category,
            'uploaded_at' => optional($file->created_at)?->toDateTimeString(),
        ])->values()->all();
    }
}

