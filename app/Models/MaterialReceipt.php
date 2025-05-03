<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialReceipt extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'supplier_id',
        'material_id',
        'user_id',
        'quantity',
        'price',
        'total_amount',
        'document_number',
        'receipt_date',
        'notes',
        'status',
        'additional_info',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'price' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'receipt_date' => 'date',
        'additional_info' => 'array',
    ];

    /**
     * Получить организацию, которой принадлежит запись.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Получить проект, к которому относится запись.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Получить поставщика материала.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Получить принятый материал.
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * Получить пользователя, создавшего запись о приемке.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Получить файлы, прикрепленные к приемке.
     */
    public function files()
    {
        return $this->morphMany(File::class, 'fileable');
    }
}
