<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use Illuminate\Database\ConnectionInterface;

final class LegalContractDocumentOrigin
{
    public static function forDocument(ConnectionInterface $connection, LegalArchiveDocument $document): bool
    {
        $schema = $connection->getSchemaBuilder();
        if ($schema->hasTable('contract_revision_legal_bindings') && $connection->table('contract_revision_legal_bindings')
            ->where('organization_id', $document->organization_id)->where('document_id', $document->id)->exists()) return true;
        if (! $schema->hasTable('contracts') || ! $schema->hasTable('contract_builder_instances')) return false;

        return $connection->table('contracts')->join('contract_builder_instances', 'contract_builder_instances.contract_id', '=', 'contracts.id')
            ->where('contracts.organization_id', $document->organization_id)->where('contracts.legal_archive_document_id', $document->id)->exists();
    }
}
