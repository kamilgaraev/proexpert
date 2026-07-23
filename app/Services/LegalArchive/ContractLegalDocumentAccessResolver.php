<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Models\Contract;

final class ContractLegalDocumentAccessResolver
{
    public function resolveDocument(int $projectId, int $contractId, int $documentId): ?ContractLegalDocumentContext
    {
        $contract = Contract::query()
            ->whereKey($contractId)
            ->where('project_id', $projectId)
            ->where('legal_archive_document_id', $documentId)
            ->first();

        if (! $contract instanceof Contract) {
            return null;
        }

        $document = LegalArchiveDocument::query()
            ->with([
                'currentVersion',
                'versions',
                'links',
                'project:id,name,status,organization_id',
                'createdBy:id,name,email',
                'files.currentVersion',
                'files.versions',
                'latestWorkflowInstance.steps',
                'obligations.responsible:id,name',
                'signatureRequests',
                'signatures',
            ])
            ->whereKey($documentId)
            ->where('organization_id', (int) $contract->organization_id)
            ->where('primary_project_id', $projectId)
            ->first();

        if (! $document instanceof LegalArchiveDocument) {
            return null;
        }

        return new ContractLegalDocumentContext($contract, $document);
    }

    public function resolveVersion(int $projectId, int $contractId, int $documentId, int $versionId): ?ContractLegalDocumentContext
    {
        $context = $this->resolveDocument($projectId, $contractId, $documentId);
        if (! $context instanceof ContractLegalDocumentContext) {
            return null;
        }

        $version = LegalArchiveDocumentVersion::query()
            ->with(['documentFile.document', 'document'])
            ->whereKey($versionId)
            ->where('organization_id', (int) $context->document->organization_id)
            ->where('document_id', (int) $context->document->id)
            ->first();

        if (! $version instanceof LegalArchiveDocumentVersion) {
            return null;
        }

        return new ContractLegalDocumentContext($context->contract, $context->document, $version);
    }
}
