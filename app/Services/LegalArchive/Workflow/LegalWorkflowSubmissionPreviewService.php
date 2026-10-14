<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Workflow\DTO\WorkflowOverride;
use App\Services\LegalArchive\Workflow\DTO\WorkflowSubmissionPreview;

final class LegalWorkflowSubmissionPreviewService
{
    private const RECIPIENT_LIMIT = 50;

    public function __construct(
        private readonly LegalDocumentAuthorizer $access,
        private readonly LegalWorkflowAuthorization $authorization,
        private readonly LegalWorkflowTemplateService $templates,
        private readonly LegalWorkflowActorResolver $actors,
    ) {}

    public function preview(LegalArchiveDocument $document, User $actor): WorkflowSubmissionPreview
    {
        $this->access->authorize($actor, $document, 'view');
        $this->authorization->assertCan($actor, $document, LegalWorkflowPermissions::SUBMIT);
        $template = $this->templates->resolveForDocument($document);
        $snapshot = $this->templates->snapshot($template, WorkflowOverride::none('submission-preview'));
        $firstSequence = min(array_column($snapshot->payload['steps'], 'sequence'));
        $steps = [];

        foreach ($snapshot->payload['steps'] as $definition) {
            $startsImmediately = (int) $definition['sequence'] === $firstSequence;
            $recipients = $startsImmediately
                ? $this->actors->recipientsForAssignment($document, $definition['actor_type'], $definition['actor_reference'])
                    ->take(self::RECIPIENT_LIMIT + 1)->map(static fn (User $user): array => [
                        'id' => (int) $user->id, 'name' => (string) $user->name,
                    ])->values()->all()
                : [];
            $steps[] = [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'sequence' => $definition['sequence'],
                'parallel_group' => $definition['parallel_group'],
                'required' => $definition['required'],
                'due_in_hours' => $definition['due_in_hours'],
                'starts_immediately' => $startsImmediately,
                'recipients' => array_slice($recipients, 0, self::RECIPIENT_LIMIT),
                'recipients_truncated' => count($recipients) > self::RECIPIENT_LIMIT,
            ];
        }

        return new WorkflowSubmissionPreview(
            (int) $document->id,
            $document->current_primary_version_id === null ? null : (int) $document->current_primary_version_id,
            (int) $document->lock_version,
            ['id' => (int) $template->id, 'name' => (string) $template->name, 'version' => (int) $template->version],
            $steps,
        );
    }
}
