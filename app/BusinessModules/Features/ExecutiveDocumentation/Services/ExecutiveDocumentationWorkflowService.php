<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentStatusEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveRemarkStatusEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Support\ExecutiveDocumentProfileRegistry;
use App\DTOs\Workflow\WorkflowSurfaceData;

final class ExecutiveDocumentationWorkflowService
{
    public function __construct(
        private readonly ExecutiveDocumentProfileRegistry $profileRegistry,
        private readonly ExecutiveDocumentRequirementsService $requirementsService,
    ) {
    }

    public function forSet(ExecutiveDocumentSet $set): WorkflowSurfaceData
    {
        $readiness = $this->readinessSummary($set);
        $documentsCount = $readiness['documents_total'];
        $openDocuments = $documentsCount - $readiness['approved_documents'];
        $availableActions = $set->status === ExecutiveDocumentStatusEnum::DRAFT
            && $readiness['ready_to_transmit']
            ? ['transmit']
            : [];
        $problemFlags = [];

        foreach ($readiness['readiness_blockers'] as $blocker) {
            $problemFlags[] = [
                'key' => $blocker['code'],
                'label' => $blocker['message'],
                'severity' => 'warning',
            ];
        }

        if ($documentsCount === 0) {
            $problemFlags[] = [
                'key' => 'no_documents',
                'label' => trans_message('executive_documentation.problem_flags.no_documents'),
                'severity' => 'warning',
            ];
        }

        if ($openDocuments > 0) {
            $problemFlags[] = [
                'key' => 'documents_not_approved',
                'label' => trans_message('executive_documentation.problem_flags.documents_not_approved'),
                'severity' => 'warning',
            ];
        }

        if ($readiness['open_remarks'] > 0) {
            $problemFlags[] = [
                'key' => 'open_remarks',
                'label' => trans_message('executive_documentation.problem_flags.open_remarks'),
                'severity' => 'warning',
            ];
        }

        if ($readiness['missing_required_data'] > 0 || $readiness['documents_with_files'] < $documentsCount) {
            $problemFlags[] = [
                'key' => 'incomplete_documents',
                'label' => trans_message('executive_documentation.errors.transmit_requires_complete_documents'),
                'severity' => 'warning',
            ];
        }

        return new WorkflowSurfaceData(
            stage: $set->status->value,
            stageLabel: $set->status->label(),
            status: $set->status->value,
            statusLabel: $set->status->label(),
            nextAction: $availableActions[0] ?? null,
            nextActionLabel: isset($availableActions[0])
                ? trans_message("executive_documentation.actions.{$availableActions[0]}")
                : null,
            availableActions: $availableActions,
            problemFlags: $problemFlags,
            meta: [
                'documents_count' => $documentsCount,
                'open_documents_count' => $openDocuments,
                'readiness_blockers' => $readiness['readiness_blockers'],
            ],
        );
    }

    /**
     * @return array<string, int|bool>
     */
    public function readinessSummary(ExecutiveDocumentSet $set): array
    {
        $set->loadMissing('documents.versions', 'documents.remarks', 'documents.workType', 'documents.journalEntry');
        $documents = $set->documents;
        $documentsTotal = $documents->count();
        $documentsWithFiles = $documents->filter(static fn (ExecutiveDocument $document): bool => $document->versions->isNotEmpty())->count();
        $approvedDocuments = $documents->filter(
            static fn (ExecutiveDocument $document): bool => in_array($document->status, [
                ExecutiveDocumentStatusEnum::APPROVED,
                ExecutiveDocumentStatusEnum::TRANSMITTED,
            ], true)
        )->count();
        $openRemarks = $documents->sum(static fn (ExecutiveDocument $document): int => $document->remarks
            ->filter(static fn ($remark): bool => in_array($remark->status, [
                ExecutiveRemarkStatusEnum::OPEN,
                ExecutiveRemarkStatusEnum::ANSWERED,
                ExecutiveRemarkStatusEnum::RETURNED,
            ], true))
            ->count());
        $missingRequiredData = $documents->filter(fn (ExecutiveDocument $document): bool => $this->documentHasMissingRequiredData($document))->count();

        $requirements = $this->requirementsService->readiness($set);

        return [
            'documents_total' => $documentsTotal,
            'documents_with_files' => $documentsWithFiles,
            'approved_documents' => $approvedDocuments,
            'open_remarks' => $openRemarks,
            'missing_required_data' => $missingRequiredData + $requirements['missing_requirements'],
            'ready_to_transmit' => $requirements['ready'] && $documentsTotal > 0
                && $documentsWithFiles === $documentsTotal
                && $approvedDocuments === $documentsTotal
                && $openRemarks === 0
                && $missingRequiredData === 0,
            'requirements_total' => $requirements['requirements_total'],
            'requirements_applicable' => $requirements['requirements_applicable'],
            'requirements_satisfied' => $requirements['requirements_satisfied'],
            'readiness_blockers' => $requirements['blockers'],
        ];
    }

    private function documentHasMissingRequiredData(ExecutiveDocument $document): bool
    {
        if (($document->metadata['capture_mode'] ?? null) === 'uploaded') {
            return false;
        }
        $profile = $this->profileRegistry->find($document->document_type->value);

        if ($profile === null) {
            return false;
        }

        if (($profile['requires_work_type'] ?? false) === true && $document->work_type_id === null) {
            return true;
        }

        if (($profile['requires_journal_entry'] ?? false) === true && $document->journal_entry_id === null) {
            return true;
        }

        return $this->profileRegistry->missingRequiredFields($document->document_type->value, $document->profile_data ?? []) !== [];
    }

    public function forDocument(ExecutiveDocument $document): WorkflowSurfaceData
    {
        $openRemarks = $document->relationLoaded('remarks')
            ? $document->remarks->filter(static fn ($remark): bool => in_array($remark->status, [
                ExecutiveRemarkStatusEnum::OPEN,
                ExecutiveRemarkStatusEnum::ANSWERED,
                ExecutiveRemarkStatusEnum::RETURNED,
            ], true))->count()
            : $document->remarks()->whereIn('status', ['open', 'answered', 'returned'])->count();
        $actions = match ($document->status) {
            ExecutiveDocumentStatusEnum::DRAFT => ['submit'],
            ExecutiveDocumentStatusEnum::REMARKS => ['submit', 'remark', 'reject'],
            ExecutiveDocumentStatusEnum::UNDER_REVIEW => $openRemarks > 0 ? ['remark', 'reject'] : ['remark', 'approve', 'reject'],
            ExecutiveDocumentStatusEnum::APPROVED => [],
            default => [],
        };
        $problemFlags = $openRemarks > 0
            ? [[
                'key' => 'open_remarks',
                'label' => trans_message('executive_documentation.problem_flags.open_remarks'),
                'severity' => 'warning',
            ]]
            : [];

        return new WorkflowSurfaceData(
            stage: $document->status->value,
            stageLabel: $document->status->label(),
            status: $document->status->value,
            statusLabel: $document->status->label(),
            nextAction: $actions[0] ?? null,
            nextActionLabel: isset($actions[0])
                ? trans_message("executive_documentation.actions.{$actions[0]}")
                : null,
            availableActions: $actions,
            problemFlags: $problemFlags,
            meta: ['open_remarks_count' => $openRemarks],
        );
    }
}
