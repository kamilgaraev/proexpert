<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentParty;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentPartySnapshotSet;
use RuntimeException;

final class ContractRevisionArchiveParties
{
    public static function attach(LegalArchiveDocument $document, LegalArchiveDocumentVersion $version, array $parties, int $actorId): void
    {
        if ((int) $version->document_id !== (int) $document->id || (int) $version->organization_id !== (int) $document->organization_id || count($parties) < 2) {
            throw new RuntimeException('contract_revision_party_snapshot_invalid');
        }
        if (LegalDocumentPartySnapshotSet::where('organization_id', $document->organization_id)->where('document_id', $document->id)
            ->where('document_version_id', $version->id)->exists()) return;
        $snapshot = LegalDocumentPartySnapshotSet::create([
            'organization_id' => $document->organization_id, 'document_id' => $document->id, 'document_version_id' => $version->id,
            'captured_at' => now(), 'captured_by_user_id' => $actorId,
        ]);
        foreach ($parties as $party) {
            LegalDocumentParty::create([
                'organization_id' => $document->organization_id, 'document_id' => $document->id,
                'document_version_id' => $version->id, 'snapshot_set_id' => $snapshot->id,
                'party_organization_id' => $party['linked_organization_id'], 'party_role' => $party['side'] === 'first' ? 'customer' : ($party['side'] === 'second' ? 'contractor' : 'other'),
                'legal_name' => $party['legal_name'] ?: $party['name'], 'tax_number' => $party['inn'],
                'registration_number' => $party['ogrn'], 'legal_address' => $party['legal_address'],
                'bank_details' => array_intersect_key($party, array_flip(['bank_name', 'bank_bic', 'settlement_account', 'correspondent_account'])),
                'representative_name' => $party['representative_name'] ?? null, 'representative_position' => $party['representative_position'] ?? null,
                'authority_basis' => $party['authority_basis'] ?? null,
                'data_source' => $party['linked_organization_id'] === null ? 'manual' : 'organization', 'snapshot' => $party,
            ]);
        }
    }
}
