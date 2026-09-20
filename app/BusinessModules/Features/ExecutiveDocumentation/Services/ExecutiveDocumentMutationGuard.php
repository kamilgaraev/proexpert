<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\ConstructionJournalEntry;
use App\Models\User;
use App\Models\WorkType;
use App\Services\Project\UserProjectAccessService;

final class ExecutiveDocumentMutationGuard
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly UserProjectAccessService $projectAccess,
    ) {}

    public function assertActor(ExecutiveDocument $document, int $userId, string $permission): void
    {
        $actor = User::query()->find($userId);
        $organizationId = (int) $document->organization_id;
        $project = $document->project;
        $set = $document->documentSet;
        if ($actor === null || (int) $actor->current_organization_id !== $organizationId
            || ! $actor->belongsToOrganization($organizationId)
            || $project === null || $set === null
            || (int) $set->organization_id !== $organizationId
            || (int) $set->project_id !== (int) $document->project_id
            || ! $this->projectAccess->canAccessProject($actor, $project, $organizationId)) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.document_not_found'), 404);
        }
        if (! $this->authorization->can($actor, $permission, [
            'organization_id' => $organizationId,
            'project_id' => (int) $document->project_id,
            'strict_project_scope' => true,
        ])) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.forbidden'), 403);
        }
    }

    public function assertReferences(ExecutiveDocument $document): void
    {
        if ($document->completed_work_id !== null && ! CompletedWork::query()
            ->whereKey($document->completed_work_id)
            ->where('organization_id', $document->organization_id)
            ->where('project_id', $document->project_id)->exists()) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.completed_work_not_found'), 404);
        }
        if ($document->journal_entry_id !== null && ! ConstructionJournalEntry::query()
            ->whereKey($document->journal_entry_id)
            ->whereHas('journal', fn ($query) => $query
                ->where('organization_id', $document->organization_id)
                ->where('project_id', $document->project_id))->exists()) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.journal_entry_not_found'), 404);
        }
        if ($document->work_type_id !== null && ! WorkType::query()
            ->whereKey($document->work_type_id)
            ->where('organization_id', $document->organization_id)->exists()) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.work_type_not_found'), 404);
        }
    }
}
