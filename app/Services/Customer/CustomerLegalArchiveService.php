<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep;
use App\Models\Contract;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAccessService;
use App\Services\LegalArchive\Files\LegalDocumentDownloadService;
use App\Services\LegalArchive\Workflow\DTO\WorkflowDecisionInput;
use App\Services\LegalArchive\Workflow\LegalDocumentWorkflowService;
use App\Services\LegalArchive\Workflow\LegalWorkflowActionResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

final class CustomerLegalArchiveService
{
    public function __construct(
        private readonly LegalDocumentAccessService $access,
        private readonly LegalWorkflowActionResolver $actions,
        private readonly LegalDocumentWorkflowService $workflow,
        private readonly LegalDocumentDownloadService $downloads,
    ) {}

    /** @return Collection<int, LegalArchiveDocument> */
    public function documents(User $actor, int $organizationId, ?Contract $contract = null): Collection
    {
        $query = LegalArchiveDocument::query()
            ->with(['project:id,name', 'currentVersion', 'versions', 'obligations'])
            ->orderByDesc('document_date')
            ->orderByDesc('id');
        $this->access->scopeAccessibleQuery($query, $actor, $organizationId);
        if ($contract instanceof Contract) {
            $query->whereHas('links', static fn ($links) => $links
                ->whereIn('linked_type', ['contract', Contract::class])
                ->where('linked_id', (int) $contract->id));
        }

        return $this->attachWorkflow($actor, $query->get());
    }

    public function document(User $actor, int $organizationId, int $documentId): ?LegalArchiveDocument
    {
        $query = LegalArchiveDocument::query()->with(['project:id,name', 'currentVersion', 'versions', 'obligations']);
        $this->access->scopeAccessibleQuery($query, $actor, $organizationId);
        $document = $query->find($documentId);

        return $document instanceof LegalArchiveDocument ? $this->attachWorkflow($actor, collect([$document]))->first() : null;
    }

    public function url(User $actor, int $organizationId, int $versionId, string $purpose): ?array
    {
        $version = LegalArchiveDocumentVersion::query()->with('document')->find($versionId);
        if (! $version instanceof LegalArchiveDocumentVersion || ! $version->document instanceof LegalArchiveDocument) {
            return null;
        }
        $document = $version->document;
        $this->assertAccessible($actor, $organizationId, $document);

        return ['url' => $this->downloads->temporaryUrl($version, $actor, $purpose), 'expires_in_seconds' => 300];
    }

    public function decide(User $actor, int $organizationId, int $stepId, string $action, array $input): void
    {
        $step = LegalWorkflowStep::query()->with('instance.document')->find($stepId);
        $document = $step?->instance?->document;
        if (! $step instanceof LegalWorkflowStep || ! $document instanceof LegalArchiveDocument) {
            throw new AuthorizationException;
        }
        $this->assertAccessible($actor, $organizationId, $document);
        $this->workflow->decide($step, $actor, new WorkflowDecisionInput(
            action: $action,
            idempotencyKey: (string) $input['idempotency_key'],
            expectedInstanceLockVersion: (int) $input['instance_lock_version'],
            expectedStepLockVersion: (int) $input['step_lock_version'],
            comment: $input['comment'] ?? null,
            reason: $input['reason'] ?? null,
        ));
    }

    /** @param Collection<int, LegalArchiveDocument> $documents */
    private function attachWorkflow(User $actor, Collection $documents): Collection
    {
        foreach ($this->actions->forMany($actor, $documents) as $id => $summary) {
            $document = $documents->firstWhere('id', $id);
            if ($document instanceof LegalArchiveDocument) {
                $document->setAttribute('customer_workflow_summary', $summary->toArray()['workflow_summary']);
            }
        }

        return $documents;
    }

    private function assertAccessible(User $actor, int $organizationId, LegalArchiveDocument $document): void
    {
        if ((int) $actor->current_organization_id !== $organizationId) {
            throw new AuthorizationException;
        }
        $this->access->authorize($actor, $document, 'view');
    }
}
