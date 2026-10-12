<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentParty;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\ConnectionInterface;

final readonly class LegalContractSignatureParticipants
{
    public function __construct(private ConnectionInterface $connection, private LegalDocumentAuthorizer $authorizer) {}

    public function forDocument(User $actor, LegalArchiveDocument $document): array
    {
        if (! LegalContractDocumentOrigin::forDocument($this->connection, $document)) return [];
        $result = ['requires_all_signature_parties' => true, 'signature_parties_available' => false, 'signature_parties' => []];
        try { $this->authorizer->authorize($actor, $document, 'request_signature'); }
        catch (AuthorizationException) { return $result; }
        $parties = LegalDocumentParty::query()->where('organization_id', $document->organization_id)->where('document_id', $document->id)
            ->where('document_version_id', $document->current_primary_version_id)->orderBy('id')->get();
        $result['signature_parties'] = $parties->map(static fn (LegalDocumentParty $party): array => [
            'signer' => (new SignerIdentity('party', $party->legal_name,
                organizationId: $party->party_organization_id === null ? null : (int) $party->party_organization_id,
                partyId: (int) $party->id, taxNumber: $party->tax_number, partyRole: $party->party_role,
                registrationNumber: $party->registration_number, position: $party->representative_position, authorityBasis: $party->authority_basis))->canonical(),
            'role_label' => $party->snapshot['role_label'] ?? trans_message('contracts.party_participant'),
        ])->all();
        $result['signature_parties_available'] = $parties->count() >= 2;
        $completedSets = $this->connection->table('legal_signature_requests')->where('organization_id', $document->organization_id)
            ->where('document_id', $document->id)->where('document_version_id', $document->current_primary_version_id)->where('status', 'completed')
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')->from('legal_signature_requests as successor')->whereColumn('successor.replaces_request_id', 'legal_signature_requests.id');
            })->pluck('signers')->map(static fn ($value): array => is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : (array) $value)->all();
        $completed = in_array($document->signature_status, ['signed', 'partially_signed'], true)
            ? $parties->filter(static fn (LegalDocumentParty $party): bool => LegalContractSignerCoverage::covers([$party->getAttributes()], $completedSets))->count() : 0;
        $result['signature_summary'] = ['status' => $document->signature_status, 'required' => $parties->count(), 'completed' => $completed];

        return $result;
    }
}
