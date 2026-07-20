<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Exceptions\ImmutableDataException;
use App\Models\Organization;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAbility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LegalDocumentAccessGrant extends Model
{
    protected $fillable = [
        'organization_id', 'document_id', 'subject_organization_id', 'subject_user_id', 'abilities',
        'granted_by_user_id', 'expires_at', 'revoked_at', 'revoked_by_user_id', 'revocation_reason',
    ];

    protected $casts = [
        'abilities' => 'array',
        'expires_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static function (self $grant): void {
            $allowed = ['revoked_at', 'revoked_by_user_id', 'revocation_reason', 'updated_at'];
            if ($grant->getOriginal('revoked_at') !== null || array_diff(array_keys($grant->getDirty()), $allowed) !== []) {
                throw new ImmutableDataException(self::class, 'update');
            }
        });
        self::deleting(static function (): never {
            throw new ImmutableDataException(self::class, 'delete');
        });
    }

    public function allows(LegalDocumentAbility $ability): bool
    {
        return in_array($ability->value, $this->abilities ?? [], true);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalArchiveDocument::class, 'document_id');
    }

    public function subjectOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'subject_organization_id');
    }

    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }
}
