<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommercialBankOperation extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount_minor' => 'integer', 'allocated_minor' => 'integer', 'booked_on' => 'immutable_date'];

    public function allocations(): HasMany
    {
        return $this->hasMany(CommercialBankAllocation::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(CommercialBankRefund::class);
    }
}
