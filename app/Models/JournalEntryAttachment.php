<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class JournalEntryAttachment extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'journal_entry_id', 'storage_key', 'file_name', 'mime_type', 'size', 'sha256', 'uploaded_by', 'created_at'];

    protected $hidden = ['storage_key', 'sha256'];

    protected $casts = ['size' => 'integer', 'uploaded_by' => 'array', 'created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('journal_attachment_immutable'));
        self::deleting(static fn () => throw new LogicException('journal_attachment_immutable'));
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ConstructionJournalEntry::class, 'journal_entry_id');
    }
}
