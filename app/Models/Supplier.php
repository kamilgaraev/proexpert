<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'inn',
        'ogrn',
        'contact_person',
        'phone',
        'email',
        'address',
        'tax_number',
        'description',
        'is_active',
        'additional_info',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'additional_info' => 'array',
    ];

    /**
     * Получить организацию, которой принадлежит поставщик.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
