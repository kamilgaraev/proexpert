<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplementaryAgreement extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contract_id',
        'number',
        'agreement_date',
        'change_amount',
        'subject_changes',
    ];

    protected $casts = [
        'agreement_date' => 'date',
        'change_amount' => 'decimal:2',
        'subject_changes' => 'array',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
} 