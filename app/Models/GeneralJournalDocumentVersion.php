<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\ImmutableDataException;
use Illuminate\Database\Eloquent\Model;

final class GeneralJournalDocumentVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'project_id', 'journal_id', 'created_by_user_id', 'revision',
        'operation_key', 'request_fingerprint', 'template_version', 'source_snapshot',
        'snapshot_hash', 'previous_version_id', 'correction_reason',
    ];

    protected $casts = [
        'organization_id' => 'integer', 'project_id' => 'integer', 'journal_id' => 'integer',
        'created_by_user_id' => 'integer', 'revision' => 'integer', 'source_snapshot' => 'array',
        'previous_version_id' => 'integer', 'created_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new ImmutableDataException(self::class, 'update');
        });
        self::deleting(static function (): never {
            throw new ImmutableDataException(self::class, 'delete');
        });
    }
}
