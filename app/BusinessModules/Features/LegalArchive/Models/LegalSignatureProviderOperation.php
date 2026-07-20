<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Exceptions\ImmutableDataException;
use Closure;
use Illuminate\Database\Eloquent\Model;

final class LegalSignatureProviderOperation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    private static int $serviceMutationDepth = 0;

    protected $fillable = [
        'id', 'organization_id', 'document_id', 'document_version_id', 'signature_request_id',
        'provider', 'status', 'correlation_id', 'provider_idempotency_key', 'lease_token_hash',
        'lease_expires_at', 'attempt_count', 'provider_request_id', 'redirect_url', 'session_expires_at',
        'session_metadata', 'last_error_code', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'lease_expires_at' => 'immutable_datetime', 'session_expires_at' => 'immutable_datetime',
        'session_metadata' => 'array', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
        'attempt_count' => 'integer',
    ];

    protected static function booted(): void
    {
        self::updating(static function (self $operation): void {
            $allowed = ['status', 'lease_token_hash', 'lease_expires_at', 'attempt_count', 'provider_request_id',
                'redirect_url', 'session_expires_at', 'session_metadata', 'last_error_code', 'started_at', 'completed_at', 'updated_at'];
            if (self::$serviceMutationDepth < 1 || array_diff(array_keys($operation->getDirty()), $allowed) !== []) {
                throw new ImmutableDataException(self::class, 'update');
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
