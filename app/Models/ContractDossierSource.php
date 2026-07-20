<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ContractDossierSource extends Model
{
    protected $fillable = [
        'organization_id',
        'contract_id',
        'source_type',
        'source_id',
        'idempotency_key',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
