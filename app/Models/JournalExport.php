<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class JournalExport extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'organization_id', 'project_id', 'journal_id', 'entry_id',
        'requested_by_user_id', 'type', 'format', 'options', 'idempotency_key',
        'request_fingerprint', 'status', 'progress', 'result_path', 'error_code',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'options' => 'array',
        'progress' => 'integer',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $export): void {
            $export->id ??= (string) Str::uuid();
        });
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(ConstructionJournal::class, 'journal_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ConstructionJournalEntry::class, 'entry_id');
    }
}
