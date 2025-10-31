<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\Contract\SupplementaryAgreementStatusEnum;

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
        'status',
    ];

    protected $casts = [
        'agreement_date' => 'date',
        'change_amount' => 'decimal:2',
        'supersede_agreement_ids' => 'array',
        'subject_changes' => 'array',
        'subcontract_changes' => 'array',
        'gp_changes' => 'array',
        'advance_changes' => 'array',
        'status' => SupplementaryAgreementStatusEnum::class,
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Проверка, является ли доп.соглашение активным
     */
    public function isActive(): bool
    {
        return $this->status === SupplementaryAgreementStatusEnum::ACTIVE;
    }

    /**
     * Проверка, аннулировано ли доп.соглашение
     */
    public function isSuperseded(): bool
    {
        return $this->status === SupplementaryAgreementStatusEnum::SUPERSEDED;
    }
} 