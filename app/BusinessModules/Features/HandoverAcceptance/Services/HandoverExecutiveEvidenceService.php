<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\ExecutiveDocumentation\Support\ExecutiveDocumentProfileRegistry;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Models\HandoverPackageDocument;
use Illuminate\Validation\ValidationException;

final readonly class HandoverExecutiveEvidenceService
{
    public function __construct(private ExecutiveDocumentProfileRegistry $profiles) {}

    public function resolveSet(AcceptanceScope $scope, ?int $setId): ?ExecutiveDocumentSet
    {
        if ($setId === null) {
            return null;
        }
        $set = ExecutiveDocumentSet::query()->whereKey($setId)
            ->where('organization_id', $scope->organization_id)->where('project_id', $scope->project_id)
            ->lockForUpdate()->first();
        if ($set === null) {
            throw ValidationException::withMessages(['executive_document_set_id' => trans_message('handover_acceptance.errors.executive_evidence_invalid')]);
        }

        return $set;
    }

    public function binding(AcceptanceScope $scope, ?ExecutiveDocumentSet $set, string $type, int $versionId): array
    {
        $version = ExecutiveDocumentVersion::query()->with('document')->whereKey($versionId)
            ->where('organization_id', $scope->organization_id)->first();
        if ($set === null || $version === null || ! $this->matches($version, $scope, (int) $set->id, $type)) {
            throw ValidationException::withMessages(['executive_document_version_id' => trans_message('handover_acceptance.errors.executive_evidence_invalid')]);
        }

        return [
            'executive_document_version_id' => $version->id,
            'evidence_hash' => $version->content_hash,
            'external_url' => $version->file_url,
            'status' => 'approved',
            'approved_at' => $version->approved_at,
        ];
    }

    public function isReady(HandoverPackageDocument $item, AcceptanceScope $scope, ?int $setId, ?int $latestVersionId): bool
    {
        if ($item->executive_document_version_id !== null) {
            $version = $item->executiveDocumentVersion;

            return $setId !== null && $version !== null
                && $latestVersionId !== null
                && $this->matches($version, $scope, $setId, $item->document_type, $latestVersionId)
                && is_string($item->evidence_hash) && hash_equals($version->content_hash, $item->evidence_hash);
        }

        return ! $this->requiresCanonicalVersion($item->document_type)
            && $item->status === 'approved' && is_string($item->external_url) && trim($item->external_url) !== '';
    }

    public function requiresCanonicalVersion(string $type): bool
    {
        return $type === 'executive_document' || $this->profiles->find($type) !== null;
    }

    private function matches(ExecutiveDocumentVersion $version, AcceptanceScope $scope, int $setId, string $type, ?int $latestVersionId = null): bool
    {
        $document = $version->document;
        if ($document === null || (int) $version->organization_id !== (int) $scope->organization_id
            || (int) $document->organization_id !== (int) $scope->organization_id
            || (int) $document->project_id !== (int) $scope->project_id
            || (int) $document->document_set_id !== $setId
            || ($type !== 'executive_document' && $type !== $document->document_type->value)
            || ! in_array($version->status, ['approved', 'transmitted'], true)
            || empty($version->content_hash) || empty($version->file_url)) {
            return false;
        }

        return ($latestVersionId ?? (int) $document->versions()->value('id')) === (int) $version->id;
    }
}
