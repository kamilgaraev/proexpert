<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentStatusEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveRemarkStatusEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\DTOs\Workflow\WorkflowSurfaceData;

final class ExecutiveDocumentationWorkflowService
{
    public function forSet(ExecutiveDocumentSet $set): WorkflowSurfaceData
    {
        $documentsCount = $set->relationLoaded('documents')
            ? $set->documents->count()
            : $set->documents()->count();
        $openDocuments = $set->relationLoaded('documents')
            ? $set->documents->whereNotIn('status', [
                ExecutiveDocumentStatusEnum::APPROVED,
                ExecutiveDocumentStatusEnum::TRANSMITTED,
            ])->count()
            : $set->documents()->whereNotIn('status', [
                ExecutiveDocumentStatusEnum::APPROVED->value,
                ExecutiveDocumentStatusEnum::TRANSMITTED->value,
            ])->count();
        $availableActions = $set->status === ExecutiveDocumentStatusEnum::DRAFT
            && $documentsCount > 0
            && $openDocuments === 0
            ? ['transmit']
            : [];
        $problemFlags = [];

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
            ],
        );
    }

    public function forDocument(ExecutiveDocument $document): WorkflowSurfaceData
    {
        $openRemarks = $document->relationLoaded('remarks')
            ? $document->remarks->filter(static fn ($remark): bool => $remark->status === ExecutiveRemarkStatusEnum::OPEN)->count()
            : $document->remarks()->where('status', 'open')->count();
        $actions = match ($document->status) {
            ExecutiveDocumentStatusEnum::DRAFT, ExecutiveDocumentStatusEnum::REMARKS => ['submit'],
            ExecutiveDocumentStatusEnum::UNDER_REVIEW => ['remark', 'approve', 'reject'],
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
