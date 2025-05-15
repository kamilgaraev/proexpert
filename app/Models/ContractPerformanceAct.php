<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractPerformanceAct extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'act_document_number',
        'act_date',
        'amount',
        'description',
        'is_approved',
        'approval_date',
    ];

    protected $casts = [
        'act_date' => 'date',
        'amount' => 'decimal:2',
        'is_approved' => 'boolean',
        'approval_date' => 'date',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
} 