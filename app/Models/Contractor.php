<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contractor extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'contact_person',
        'phone',
        'email',
        'legal_address',
        'inn',
        'kpp',
        'bank_details',
        'notes',
    ];

    protected $casts = [
        // При необходимости можно добавить касты
    ];

    /**
     * Организация-пользователь, к которой относится этот подрядчик.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Договоры, заключенные с этим подрядчиком.
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }
} 