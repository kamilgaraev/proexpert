<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ExecutiveDocumentTransmittal extends Model
{
    protected $fillable = [
        'organization_id',
        'document_set_id',
        'transmitted_by',
        'acknowledged_by',
        'transmittal_number',
        'comment',
        'acknowledgement_comment',
        'transmitted_at',
        'acknowledged_at',
        'metadata',
    ];

    protected $casts = [
        'transmitted_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function documentSet(): BelongsTo
    {
        return $this->belongsTo(ExecutiveDocumentSet::class, 'document_set_id');
    }

    public function transmittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transmitted_by');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
