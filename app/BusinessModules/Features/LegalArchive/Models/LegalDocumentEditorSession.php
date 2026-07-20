<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Exceptions\ImmutableDataException;
use Closure;
use Illuminate\Database\Eloquent\Model;

final class LegalDocumentEditorSession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    private static int $serviceMutationDepth = 0;

    protected $fillable = [
        'id', 'organization_id', 'document_id', 'source_version_id', 'document_file_id',
        'opened_by_user_id', 'provider', 'mode', 'status', 'generation', 'document_key',
        'source_content_hash', 'saved_version_id',
        'expires_at', 'completed_at', 'failure_code', 'next_save_generation',
    ];

    protected $casts = [
        'generation' => 'integer', 'next_save_generation' => 'integer',
        'last_applied_generation' => 'integer', 'final_generation' => 'integer',
        'expires_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static function (self $session): void {
            $allowed = ['status', 'saved_version_id', 'completed_at', 'failure_code',
                'next_save_generation', 'updated_at'];
            if (self::$serviceMutationDepth < 1 || array_diff(array_keys($session->getDirty()), $allowed) !== []) {
                throw new ImmutableDataException(self::class, 'update');
            }
            $from = (string) $session->getOriginal('status');
            $to = (string) $session->status;
            $transitions = [
                'active' => ['active', 'processing', 'expired', 'closed'],
                'processing' => ['active', 'processing', 'completed', 'failed', 'expired', 'closed'],
            ];
            if (! isset($transitions[$from]) || ! in_array($to, $transitions[$from], true)) {
                if ($to !== $from || array_diff(array_keys($session->getDirty()), ['updated_at']) !== []) {
                    throw new ImmutableDataException(self::class, 'transition');
                }
            }
            if ($session->isDirty('saved_version_id')
                    && ! ($from === 'processing' && $to === 'completed'
                        && $session->getOriginal('saved_version_id') === null && $session->saved_version_id !== null)) {
                throw new ImmutableDataException(self::class, 'update');
            }
            if ($session->isDirty('next_save_generation')
                && (int) $session->next_save_generation !== ((int) $session->getOriginal('next_save_generation')) + 1) {
                throw new ImmutableDataException(self::class, 'generation');
            }
            if ($session->isDirty('last_applied_generation') || $session->isDirty('final_generation')) {
                throw new ImmutableDataException(self::class, 'database_generation_fence');
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
