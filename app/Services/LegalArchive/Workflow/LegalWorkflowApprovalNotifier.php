<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep;
use App\Notifications\LegalArchive\LegalDocumentApprovalRequiredNotification;
use App\Services\LegalArchive\LegalDocumentNotificationPublisher;

final class LegalWorkflowApprovalNotifier
{
    public function __construct(
        private readonly LegalWorkflowActorResolver $actors,
        private readonly LegalDocumentNotificationPublisher $publisher,
    ) {}

    public function publishForStep(LegalArchiveDocument $document, LegalWorkflowStep $step): void
    {
        $notification = new LegalDocumentApprovalRequiredNotification($document, $step);
        foreach ($this->actors->recipientsFor($step, $document) as $recipient) {
            $key = 'workflow-step:'.$step->id.':'.$recipient->id;
            if ((int) $step->assignment_revision > 0) {
                $key .= ':assignment:'.$step->assignment_revision;
            }
            $this->publisher->publish($document, $recipient, $key, $notification);
        }
    }
}
