<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Exceptions\ImmutableDataException;
use Closure;
use Illuminate\Database\Eloquent\Model;

final class LegalDocumentEditorSave extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    private static int $serviceMutationDepth = 0;

    protected $fillable = [
        'id', 'organization_id', 'document_id', 'editor_session_id', 'source_version_id',
        'document_file_id', 'save_generation', 'callback_status', 'replay_hash', 'operation_id',
        'state', 'lease_owner_hash', 'lease_expires_at', 'saved_version_id', 'content_hash',
        'terminal', 'completed_at', 'failed_at',
    ];

    protected $casts = [
        'save_generation' => 'integer',
        'callback_status' => 'integer',
        'terminal' => 'boolean',
        'lease_expires_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static function (self $save): void {
            $allowed = ['state', 'lease_owner_hash', 'lease_expires_at', 'saved_version_id',
                'content_hash', 'completed_at', 'failed_at', 'updated_at'];
            if (self::$serviceMutationDepth < 1 || array_diff(array_keys($save->getDirty()), $allowed) !== []) {
                throw new ImmutableDataException(self::class, 'update');
            }
            $from = (string) $save->getOriginal('state');
            $to = (string) $save->state;
            $transitions = [
                'reserved' => ['reserved', 'processing', 'failed'],
                'processing' => ['processing', 'reserved', 'completed', 'failed'],
                'failed' => ['failed', 'processing'],
                'completed' => ['completed'],
            ];
            if (! in_array($to, $transitions[$from] ?? [], true)
                || ($from === 'completed' && array_diff(array_keys($save->getDirty()), ['updated_at']) !== [])) {
                throw new ImmutableDataException(self::class, 'transition');
            }
            if ($save->getOriginal('saved_version_id') !== null && $save->isDirty('saved_version_id')) {
                throw new ImmutableDataException(self::class, 'result');
            }
        });
        self::deleting(static function (): never {
            throw new ImmutableDataException(self::class, 'delete');
        });
    }

    public static function serviceMutation(Closure $mutation): mixed
    {
        self::$serviceMutationDepth++;
        try {
            return $mutation();
        } finally {
            self::$serviceMutationDepth--;
        }
    }
}
