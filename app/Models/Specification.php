<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Specification extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'number',
        'spec_date',
        'total_amount',
        'scope_items',
        'status',
    ];

    protected $casts = [
        'spec_date' => 'date',
        'total_amount' => 'decimal:2',
        'scope_items' => 'array',
    ];

    public function contracts(): BelongsToMany
    {
        return $this->belongsToMany(Contract::class, 'contract_specification')
                    ->withPivot('attached_at')
                    ->withTimestamps();
    }
} 