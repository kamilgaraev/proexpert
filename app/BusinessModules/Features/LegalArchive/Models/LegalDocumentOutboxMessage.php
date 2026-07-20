<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LegalDocumentOutboxMessage extends Model
{
    use HasUuids;

    protected $table = 'legal_document_outbox';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'aggregate_type',
        'aggregate_id',
        'event',
        'payload',
        'payload_hash',
        'idempotency_key',
        'attempts',
        'available_at',
        'published_at',
        'last_error',
        'claim_token',
        'claimed_at',
        'dead_lettered_at',
        'reconciliation_required_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'payload' => 'array',
        'attempts' => 'integer',
        'available_at' => 'datetime',
        'published_at' => 'datetime',
        'claimed_at' => 'datetime',
        'dead_lettered_at' => 'datetime',
        'reconciliation_required_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
