<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplementaryAgreement extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contract_id',
        'number',
        'agreement_date',
        'change_amount',
        'supersede_agreement_ids',
        'subject_changes',
        'subcontract_changes',
        'gp_changes',
        'advance_changes',
        'applied_at',
        'applied_by_user_id',
        'application_key',
    ];

    protected $casts = [
        'agreement_date' => 'date',
        'change_amount' => 'decimal:2',
        'supersede_agreement_ids' => 'array',
        'subject_changes' => 'array',
        'subcontract_changes' => 'array',
        'gp_changes' => 'array',
        'advance_changes' => 'array',
        'applied_at' => 'datetime',
        'applied_by_user_id' => 'integer',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
