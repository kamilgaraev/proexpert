<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ContractPeriodCertificateAct extends Model
{
    protected $fillable = [
        'certificate_id',
        'act_id',
        'act_status',
        'amount',
        'vat_amount',
        'vat_rate',
        'amount_without_vat',
        'execution_date',
        'document_date',
        'signed_at',
        'is_binding',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'amount_without_vat' => 'decimal:2',
        'execution_date' => 'date',
        'document_date' => 'date',
        'signed_at' => 'datetime',
        'is_binding' => 'boolean',
    ];

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(ContractPeriodCertificate::class, 'certificate_id');
    }

    public function act(): BelongsTo
    {
        return $this->belongsTo(ContractPerformanceAct::class, 'act_id');
    }
}
