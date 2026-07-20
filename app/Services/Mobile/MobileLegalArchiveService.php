<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\LegalArchiveRegistryService;
use App\Services\LegalArchive\Workflow\DTO\WorkflowDecisionInput;
use App\Services\LegalArchive\Workflow\LegalDocumentWorkflowService;
use App\Services\LegalArchive\Workflow\LegalWorkflowActionResolver;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final readonly class MobileLegalArchiveService
{
    public function __construct(
        private LegalArchiveRegistryService $registry,
        private LegalDocumentAuthorizer $authorizer,
        private LegalWorkflowActionResolver $actions,
        private LegalDocumentWorkflowService $workflow,
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

        return $document->loadMissing(['obligations']);
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

        return $this->document($actor, $organizationId, $documentId)->fresh(['project', 'currentVersion', 'versions', 'obligations']) ?? $document;
    }
}
