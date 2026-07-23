<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Exceptions\ImmutableDataException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LegalDocumentSignature extends Model
{
    protected $fillable = [
        'organization_id', 'document_id', 'document_version_id', 'signature_request_id', 'party_id',
        'method', 'provider', 'signer_name', 'signers', 'signed_content_hash', 'signature_path',
        'signature_content_hash', 'storage_version_id', 'storage_etag', 'detected_mime_type',
        'certificate_metadata', 'provider_metadata', 'storage_location',
        'signed_at', 'verified_at', 'verification_status', 'revocation_reason', 'registered_by_user_id',
        'signature_kind', 'container_format', 'signer_snapshot_hash', 'signer_user_id',
        'signer_organization_id', 'party_role_snapshot', 'certificate_fingerprint', 'certificate_serial',
        'certificate_issuer', 'certificate_valid_from', 'certificate_valid_until', 'authority_confirmed',
        'time_source', 'diagnostic_code', 'signing_session_id', 'client_ip_hash', 'user_agent_hash',
        'idempotency_key', 'request_hash',
    ];

    protected $casts = [
        'signers' => 'array', 'certificate_metadata' => 'array', 'provider_metadata' => 'array',
        'signed_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime',
        'certificate_valid_from' => 'immutable_datetime', 'certificate_valid_until' => 'immutable_datetime',
        'authority_confirmed' => 'boolean',
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

    public function request(): BelongsTo
    {
        return $this->belongsTo(LegalSignatureRequest::class, 'signature_request_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalArchiveDocument::class, 'document_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(LegalArchiveDocumentVersion::class, 'document_version_id');
    }

    public function verificationHistory(): HasMany
    {
        return $this->hasMany(LegalSignatureVerification::class, 'signature_id')->orderBy('id');
    }
}
