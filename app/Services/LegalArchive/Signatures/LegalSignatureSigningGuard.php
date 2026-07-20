<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalSignatureRequest;
use App\Services\LegalArchive\CanonicalJson;
use App\Services\LegalArchive\Comments\LegalDocumentBlockingCommentGuard;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileRegistry;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileValidator;
use DomainException;
use Illuminate\Database\ConnectionInterface;

final readonly class LegalSignatureSigningGuard
{
    public function __construct(
        private LegalDocumentProfileRegistry $profiles,
        private LegalDocumentProfileValidator $profileValidator,
        private LegalDocumentBlockingCommentGuard $blockingComments,
        private ConnectionInterface $connection,
    ) {}

    public function assertRequestAllowed(
        LegalArchiveDocument $document,
        LegalArchiveDocumentVersion $version,
        string $method,
    ): array {
        $this->assertAggregateState($document, $version);
        $profile = $this->profiles->find((int) $document->organization_id, (string) $document->type_profile_code);
        $this->profileValidator->validate($profile, (array) $document->structured_fields);
        $kind = $method === 'paper' ? 'paper_original' : $method;
        if (! $profile->requiresSignature || ! in_array($kind, $profile->allowedSignatureKinds, true)) {
            throw new DomainException('legal_signature_method_not_allowed_by_profile');
        }

        return [
            'profile_code' => $profile->code,
            'profile_lock_version' => $profile->lockVersion,
            'allowed_signature_kinds' => $profile->allowedSignatureKinds,
            'required_signature_kinds' => $profile->requiredSignatureKinds,
        ];
    }

    public function assertCompletionAllowed(
        LegalArchiveDocument $document,
        LegalArchiveDocumentVersion $version,
        LegalSignatureRequest $request,
    ): void {
        $this->assertAggregateState($document, $version);
        if ((int) $request->document_id !== (int) $document->id
            || (int) $request->document_version_id !== (int) $version->id
            || (int) $request->organization_id !== (int) $document->organization_id
            || ! hash_equals((string) $request->signed_content_hash, (string) $version->content_hash)
            || $request->status !== 'pending'
            || ($request->expires_at !== null && $request->expires_at->isPast())) {
            throw new DomainException('legal_signature_request_not_pending');
        }
        $requirements = $this->assertRequestAllowed($document, $version, (string) $request->method);
        if (! hash_equals((string) $request->requirement_snapshot_hash, CanonicalJson::fingerprint($requirements))) {
            throw new DomainException('legal_signature_profile_changed');
        }
    }

    private function assertAggregateState(
        LegalArchiveDocument $document,
        LegalArchiveDocumentVersion $version,
    ): void {
        if ($document->approval_status !== 'approved'
            || ! in_array((string) $document->lifecycle_status, ['approved', 'signing', 'partially_signed', 'signature_failed'], true)
            || $document->archived_at !== null || $document->deleted_at !== null) {
            throw new DomainException('legal_signature_lifecycle_invalid');
        }
        if ((int) $document->current_primary_version_id !== (int) $version->id
            || ! (bool) $version->is_current
            || $version->processing_status !== 'ready'
            || preg_match('/^[a-f0-9]{64}$/D', (string) $version->content_hash) !== 1) {
            throw new DomainException('legal_signature_version_not_ready');
        }
        if ($this->connection->getSchemaBuilder()->hasTable('legal_workflow_instances')
            && $this->connection->table('legal_workflow_instances')->where('document_id', $document->id)->where('status', 'in_progress')->exists()) {
            throw new DomainException('legal_signature_active_workflow_exists');
        }
        if ($this->connection->getSchemaBuilder()->hasTable('legal_document_comments')) {
            $this->blockingComments->assertNone($document, (int) $version->id);
        }
    }
}
