<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalSignatureRequest;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Files\LegalDocumentDownloadService;
use App\Services\LegalArchive\LegalArchiveRegistryService;
use App\Services\LegalArchive\Signatures\LegalDocumentSignatureService;
use App\Services\LegalArchive\Signatures\PaperOriginalData;
use App\Services\LegalArchive\Signatures\SignerIdentitySet;
use App\Services\LegalArchive\Workflow\DTO\WorkflowDecisionInput;
use App\Services\LegalArchive\Workflow\LegalDocumentWorkflowService;
use App\Services\LegalArchive\Workflow\LegalWorkflowActionResolver;
use DomainException;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Http\UploadedFile;

final readonly class MobileLegalArchiveService
{
    public function __construct(
        private LegalArchiveRegistryService $registry,
        private LegalDocumentAuthorizer $authorizer,
        private LegalWorkflowActionResolver $actions,
        private LegalDocumentWorkflowService $workflow,
        private LegalDocumentDownloadService $downloads,
        private LegalDocumentSignatureService $signatures,
    ) {}

    public function documents(User $actor, int $organizationId, int $projectId): LengthAwarePaginator
    {
        return $this->registry->paginate($actor, $organizationId, [
            'project_id' => $projectId,
            'per_page' => 50,
            'sort_by' => 'updated_at',
            'sort_direction' => 'desc',
        ]);
    }

    public function document(User $actor, int $organizationId, int $documentId): LegalArchiveDocument
    {
        $document = $this->registry->findForOrganization($organizationId, $documentId);
        if (! $document instanceof LegalArchiveDocument) {
            throw new DomainException('legal_archive_document_not_found');
        }
        $this->authorizer->authorize($actor, $document, 'view');

        return $document->loadMissing(['currentVersion', 'versions', 'obligations', 'signatureRequests']);
    }

    /** @return array<string, mixed> */
    public function summary(User $actor, LegalArchiveDocument $document): array
    {
        return $this->summaries($actor, collect([$document]))[(int) $document->id];
    }

    /** @param Collection<int, LegalArchiveDocument> $documents
     *  @return array<int, array<string, mixed>>
     */
    public function summaries(User $actor, Collection $documents): array
    {
        $resolved = $this->actions->forMany($actor, $documents);
        $summaries = [];
        foreach ($resolved as $documentId => $summary) {
            $payload = $summary->toArray()['workflow_summary'];
            $payload['available_action_details'] = array_values(array_filter(
                $payload['available_action_details'],
                static fn (array $detail): bool => in_array($detail['action'] ?? null, ['approve', 'reject', 'return'], true),
            ));
            $summaries[(int) $documentId] = $payload;
        }

        return $summaries;
    }

    public function decide(User $actor, int $organizationId, int $documentId, string $action, int $targetStepId, string $idempotencyKey, int $instanceLockVersion, int $stepLockVersion, ?string $comment, ?string $reason): LegalArchiveDocument
    {
        $document = $this->document($actor, $organizationId, $documentId);
        $summary = $this->actions->forMany($actor, collect([$document]))[(int) $document->id];
        $detail = $summary->action($action, $targetStepId);
        if (! $detail->enabled || $detail->targetStepId === null || $detail->expectedInstanceLockVersion !== $instanceLockVersion || $detail->expectedStepLockVersion !== $stepLockVersion) {
            throw new DomainException('legal_workflow_action_not_available');
        }
        $step = LegalWorkflowStep::query()
            ->where('organization_id', $organizationId)
            ->find($detail->targetStepId);
        if (! $step instanceof LegalWorkflowStep) {
            throw new DomainException('legal_workflow_step_not_found');
        }
        $this->workflow->decide($step, $actor, new WorkflowDecisionInput(
            action: $action,
            idempotencyKey: $idempotencyKey,
            expectedInstanceLockVersion: $instanceLockVersion,
            expectedStepLockVersion: $stepLockVersion,
            comment: $comment,
            reason: $reason,
        ));

        return $this->document($actor, $organizationId, $documentId)->fresh(['project', 'currentVersion', 'versions', 'obligations', 'signatureRequests']) ?? $document;
    }

    /** @return array{url: string, expires_in_seconds: int} */
    public function versionUrl(User $actor, int $organizationId, int $documentId, int $versionId, string $purpose): array
    {
        if (! in_array($purpose, ['preview', 'download'], true)) {
            throw new DomainException('legal_archive_file_purpose_invalid');
        }
        $document = $this->document($actor, $organizationId, $documentId);
        $version = LegalArchiveDocumentVersion::query()
            ->whereKey($versionId)
            ->where('organization_id', $organizationId)
            ->where('document_id', (int) $document->id)
            ->with('documentFile.document')
            ->first();
        if (! $version instanceof LegalArchiveDocumentVersion) {
            throw new DomainException('legal_archive_document_not_found');
        }

        return [
            'url' => $this->downloads->temporaryUrl($version, $actor, $purpose),
            'expires_in_seconds' => 300,
        ];
    }

    public function uploadPaperOriginal(
        User $actor,
        int $organizationId,
        int $signatureRequestId,
        UploadedFile $upload,
        DateTimeImmutable $signedAt,
        int $documentLockVersion,
        string $idempotencyKey,
    ): void {
        $request = LegalSignatureRequest::query()->with('document')->whereKey($signatureRequestId)
            ->where('organization_id', $organizationId)->first();
        $document = $request?->document;
        if (! $request instanceof LegalSignatureRequest || ! $document instanceof LegalArchiveDocument) {
            throw new DomainException('legal_archive_document_not_found');
        }
        $this->document($actor, $organizationId, (int) $document->id);
        if ((string) $request->method !== 'paper' || ! $upload->isValid() || (int) $upload->getSize() < 1 || (int) $upload->getSize() > 20 * 1024 * 1024) {
            throw new DomainException('legal_signature_paper_original_invalid');
        }
        $content = $upload->getContent();
        $mimeType = is_string($content) && $content !== '' ? (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) : null;
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            default => null,
        };
        if ($extension === null || ! is_string($content)) {
            throw new DomainException('legal_signature_paper_original_invalid');
        }
        $path = "org-{$organizationId}/legal-archive/paper-originals/requests/{$request->id}/".hash('sha256', $content).".{$extension}";
        $original = new PaperOriginalData(
            signedAt: $signedAt,
            signers: SignerIdentitySet::fromSnapshot([[
                'kind' => 'user',
                'name' => (string) $actor->name,
                'user_id' => (int) $actor->id,
                'organization_id' => $organizationId,
            ]]),
            storageLocation: $path,
            idempotencyKey: $idempotencyKey,
            expectedDocumentLockVersion: $documentLockVersion,
        );
        $this->signatures->registerPaperOriginalArtifact($request, $actor, $original, $content, $mimeType);
    }
}
