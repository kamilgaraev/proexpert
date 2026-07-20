<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Exceptions\ImmutableDataException;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LegalDocumentComment extends Model
{
    protected $fillable = [
        'organization_id', 'document_id', 'document_version_id', 'author_user_id', 'body', 'page_number',
        'anchor', 'visibility', 'is_blocking', 'status', 'resolution', 'resolved_by_user_id', 'resolved_at',
        'idempotency_key', 'request_hash', 'resolution_idempotency_key', 'resolution_request_hash',
    ];

    protected $casts = [
        'page_number' => 'integer', 'anchor' => 'array', 'is_blocking' => 'boolean', 'resolved_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static function (self $comment): void {
            $allowed = [
                'status', 'resolution', 'resolved_by_user_id', 'resolved_at',
                'resolution_idempotency_key', 'resolution_request_hash', 'updated_at',
            ];
            if (
                $comment->getOriginal('status') !== 'open'
                || $comment->status !== 'resolved'
                || array_diff(array_keys($comment->getDirty()), $allowed) !== []
            ) {
                throw new ImmutableDataException(self::class, 'update');
            }
        });
        self::deleting(static function (): never {
            throw new ImmutableDataException(self::class, 'delete');
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalArchiveDocument::class, 'document_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(LegalArchiveDocumentVersion::class, 'document_version_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
