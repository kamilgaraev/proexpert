<?php

declare(strict_types=1);

namespace App\Notifications\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep;
use App\Models\Contract;
use Illuminate\Notifications\Notification;

final class LegalDocumentApprovalRequiredNotification extends Notification
{
    private ?string $documentRoute = null;

    public function __construct(
        private readonly LegalArchiveDocument $document,
        private readonly ?LegalWorkflowStep $workflowStep = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'legal_document_approval_required',
            'document_id' => (int) $this->document->id,
            'title' => $this->document->title,
            'message' => 'Требуется согласование юридического документа',
            'targetRoute' => $this->documentRoute ??= $this->resolveDocumentRoute(),
            ...($this->workflowStep === null ? [] : [
                'workflow_step_id' => (int) $this->workflowStep->id,
                'workflow_assignment_revision' => (int) $this->workflowStep->assignment_revision,
                'workflow_actor_type' => (string) $this->workflowStep->actor_type,
                'workflow_actor_reference' => (string) $this->workflowStep->actor_reference,
            ]),
        ];
    }

    private function resolveDocumentRoute(): string
    {
        $documentId = (int) $this->document->id;
        if ($this->document->primary_project_id !== null) {
            $projectId = (int) $this->document->primary_project_id;
            $contractId = (new Contract)->setConnection($this->document->getConnectionName())->newQuery()
                ->where('organization_id', (int) $this->document->organization_id)
                ->where('project_id', $projectId)
                ->where('legal_archive_document_id', $documentId)
                ->orderBy('id')
                ->value('id');
            if ($contractId !== null) {
                return '/projects/'.$projectId.'/contracts/'.(int) $contractId.'/documents/'.$documentId;
            }
        }

        return '/legal-archive/'.$documentId;
    }
}
