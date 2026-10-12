<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use Illuminate\Database\ConnectionInterface;

final readonly class LegalContractSignerCoverage
{
    public function __construct(private ConnectionInterface $connection) {}

    public function satisfied(LegalArchiveDocument $document, array $completedSignerSets): bool
    {
        if (! LegalContractDocumentOrigin::forDocument($this->connection, $document)) return true;
        $parties = $this->connection->table('legal_document_parties')->where('organization_id', $document->organization_id)
            ->where('document_id', $document->id)->where('document_version_id', $document->current_primary_version_id)->get()
            ->map(static fn (object $party): array => (array) $party)->all();

        return self::covers($parties, $completedSignerSets);
    }

    public static function covers(array $parties, array $signerSets): bool
    {
        if ($parties === []) return false;
        foreach ($parties as $party) {
            $covered = false;
            foreach ($signerSets as $signers) {
                foreach ($signers as $signer) {
                    if (($signer['kind'] ?? null) === 'party' && (int) ($signer['party_id'] ?? 0) === (int) $party['id']) $covered = true;
                    if (($signer['kind'] ?? null) === 'organization' && !empty($party['party_organization_id']) && !empty($party['tax_number'])
                        && (int) ($signer['organization_id'] ?? 0) === (int) $party['party_organization_id']
                        && ($signer['tax_number'] ?? null) === $party['tax_number']) $covered = true;
                }
            }
            if (! $covered) return false;
        }

        return true;
    }
}
