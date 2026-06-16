<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Tenders\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TenderDeadlineReminder extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'tender_id',
        'deadline_id',
        'policy_key',
        'channel',
        'scheduled_for',
        'sent_at',
        'failed_at',
        'status',
        'attempt_count',
        'last_error',
        'metadata',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function deadline(): BelongsTo
    {
        return $this->belongsTo(TenderDeadline::class);
    }
}
