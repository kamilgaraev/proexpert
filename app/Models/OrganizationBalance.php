<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'balance', // Сумма в минорных единицах (копейках)
        'currency', // Валюта баланса (RUB, USD и т.д.)
    ];

    protected $casts = [
        'balance' => 'integer', // Храним как integer (копейки)
    ];

    public function organization(): BelongsTo
    {
        // Убедитесь, что модель Organization существует и находится в App\Models\Organization
        return $this->belongsTo(Organization::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BalanceTransaction::class);
    }

    /**
     * Форматированный баланс для отображения (в рублях).
     */
    public function getFormattedBalanceAttribute(): string
    {
        return number_format($this->balance / 100, 2, '.', ' ');
    }
} 