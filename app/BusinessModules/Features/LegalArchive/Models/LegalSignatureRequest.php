<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Exceptions\ImmutableDataException;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LegalSignatureRequest extends Model
{
    private static int $serviceMutationDepth = 0;

    protected $fillable = [
        'organization_id', 'document_id', 'document_version_id', 'party_id', 'method', 'provider',
        'status', 'signed_content_hash', 'signers', 'signer_snapshot_hash', 'correlation_id', 'provider_request_id',
        'profile_code', 'profile_lock_version', 'allowed_signature_kinds', 'required_signature_kinds', 'allowed_signature_formats', 'requirement_snapshot_hash',
        'requirement_group_key', 'replaces_request_id',
        'callback_replay_hash', 'callback_payload_hash', 'session_metadata', 'idempotency_key',
        'request_hash', 'requested_by_user_id', 'requested_at', 'expires_at', 'completed_at',
    ];

    protected $casts = [
        'signers' => 'array', 'allowed_signature_kinds' => 'array', 'required_signature_kinds' => 'array', 'allowed_signature_formats' => 'array',
        'profile_lock_version' => 'integer', 'session_metadata' => 'array', 'requested_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static function (self $request): void {
            $allowed = ['status', 'provider_request_id', 'callback_replay_hash', 'callback_payload_hash', 'session_metadata', 'completed_at', 'updated_at'];
            if (self::$serviceMutationDepth < 1 || array_diff(array_keys($request->getDirty()), $allowed) !== []) {
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

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalArchiveDocument::class, 'document_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(LegalArchiveDocumentVersion::class, 'document_version_id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(LegalDocumentSignature::class, 'signature_request_id');
    }
}
