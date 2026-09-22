<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ContractSupplementaryDocument extends Model
{
    protected $table = 'contract_supplementary_documents';

    protected $casts = [
        'agreement_date' => 'date',
        'frame_document' => 'array',
        'frame_definitions' => 'array',
        'revision_document' => 'array',
        'revision_definitions' => 'array',
        'parties' => 'array',
        'entity_snapshots' => 'array',
        'base_values' => 'array',
        'values' => 'array',
        'change_amount' => 'decimal:2',
        'applied_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
