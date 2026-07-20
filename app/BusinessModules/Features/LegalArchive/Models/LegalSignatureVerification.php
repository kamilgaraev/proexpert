<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Exceptions\ImmutableDataException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LegalSignatureVerification extends Model
{
    protected $fillable = [
        'organization_id', 'document_id', 'document_version_id', 'signature_id', 'provider', 'status',
        'signed_content_hash', 'certificate_metadata', 'provider_metadata', 'revocation_reason',
        'verified_by_user_id', 'verified_at', 'idempotency_key', 'request_hash',
    ];

    protected $casts = [
        'certificate_metadata' => 'array', 'provider_metadata' => 'array', 'verified_at' => 'immutable_datetime',
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

    public function signature(): BelongsTo
    {
        return $this->belongsTo(LegalDocumentSignature::class, 'signature_id');
    }
}
