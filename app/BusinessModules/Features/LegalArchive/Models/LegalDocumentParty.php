<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Exceptions\ImmutableDataException;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LegalDocumentParty extends Model
{
    protected $fillable = [
        'organization_id', 'document_id', 'party_organization_id', 'counterparty_id', 'party_role',
        'legal_name', 'tax_number', 'registration_number', 'legal_address', 'bank_details',
        'representative_name', 'representative_position', 'authority_basis', 'data_source', 'snapshot',
    ];

    protected $casts = ['bank_details' => 'array', 'snapshot' => 'array'];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new ImmutableDataException(self::class, 'update');
        });
        self::deleting(static function (): never {
            throw new ImmutableDataException(self::class, 'delete');
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalArchiveDocument::class, 'document_id');
    }

    public function partyOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'party_organization_id');
    }
}
