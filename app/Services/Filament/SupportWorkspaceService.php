<?php

declare(strict_types=1);

namespace App\Services\Filament;

use App\Enums\Activity\ActivityActionEnum;
use App\Mail\SupportTicketReplyMail;
use App\Models\Activity\ActivityEvent;
use App\Models\ContactForm;
use App\Models\Organization;
use App\Models\SystemAdmin;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

use function trans_message;

final class SupportWorkspaceService
{
    /**
     * @var list<string>
     */
    private const ALLOWED_STATUSES = [
        ContactForm::STATUS_NEW,
        ContactForm::STATUS_PROCESSING,
        ContactForm::STATUS_COMPLETED,
        ContactForm::STATUS_CANCELLED,
    ];

    public function __construct(
        private readonly SystemAdminAuditService $auditService,
    ) {}

    public function assign(ContactForm $supportRequest, ?int $systemAdminId, SystemAdmin $actor): ?ActivityEvent
    {
        return DB::transaction(function () use ($supportRequest, $systemAdminId, $actor): ?ActivityEvent {
            $supportRequest->refresh();
            $assignee = $this->resolveSystemAdmin($systemAdminId);
            $before = $this->stateSnapshot($supportRequest);

            $supportRequest->forceFill([
                'assigned_system_admin_id' => $assignee?->id,
                'last_activity_at' => now(),
            ])->save();

            return $this->recordSupportAction(
                actor: $actor,
                supportRequest: $supportRequest->refresh(),
                eventType: 'system_admin.support.assigned',
                action: ActivityActionEnum::Assigned,
                titleKey: 'filament_actions.audit.support_assigned_title',
                descriptionKey: 'filament_actions.audit.support_assigned_description',
                before: $before,
                after: $this->stateSnapshot($supportRequest),
                operation: 'assign',
                context: [
                    'assigned_system_admin_id' => $assignee?->id,
                ],
            );
        });
    }

    public function changeStatus(ContactForm $supportRequest, string $status, SystemAdmin $actor): ?ActivityEvent
    {
        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new DomainException(trans_message('support_workspace.errors.invalid_status'));
        }

        return DB::transaction(function () use ($supportRequest, $status, $actor): ?ActivityEvent {
            $supportRequest->refresh();
            $before = $this->stateSnapshot($supportRequest);
            $now = now();
            $updates = [
                'status' => $status,
                'last_activity_at' => $now,
            ];

            if (in_array($status, [ContactForm::STATUS_PROCESSING, ContactForm::STATUS_COMPLETED, ContactForm::STATUS_CANCELLED], true)) {
                $updates['is_processed'] = true;
                $updates['processed_at'] = $supportRequest->processed_at ?? $now;
            }

            $supportRequest->forceFill($updates)->save();

            return $this->recordSupportAction(
                actor: $actor,
                supportRequest: $supportRequest->refresh(),
                eventType: 'system_admin.support.status_changed',
                action: ActivityActionEnum::Updated,
                titleKey: 'filament_actions.audit.support_status_changed_title',
                descriptionKey: 'filament_actions.audit.support_status_changed_description',
                before: $before,
                after: $this->stateSnapshot($supportRequest),
                operation: 'change_status',
                context: [
                    'status' => $status,
                ],
            );
        });
    }

    public function addInternalNote(ContactForm $supportRequest, string $body, SystemAdmin $actor): ?ActivityEvent
    {
        $body = trim($body);

        if ($body === '') {
            throw new DomainException(trans_message('support_workspace.errors.empty_note'));
        }

        return DB::transaction(function () use ($supportRequest, $body, $actor): ?ActivityEvent {
            $supportRequest->refresh();
            $before = $this->stateSnapshot($supportRequest);
            $notes = is_array($supportRequest->internal_notes) ? $supportRequest->internal_notes : [];
            $notes[] = [
                'type' => 'internal_note',
                'body' => $body,
                'author_system_admin_id' => (int) $actor->id,
                'author_name' => $actor->name,
                'created_at' => now()->toISOString(),
            ];

            $supportRequest->forceFill([
                'internal_notes' => array_values($notes),
                'last_activity_at' => now(),
            ])->save();

            return $this->recordSupportAction(
                actor: $actor,
                supportRequest: $supportRequest->refresh(),
                eventType: 'system_admin.support.internal_note_added',
                action: ActivityActionEnum::Updated,
                titleKey: 'filament_actions.audit.support_internal_note_added_title',
                descriptionKey: 'filament_actions.audit.support_internal_note_added_description',
                before: $before,
                after: $this->stateSnapshot($supportRequest),
                operation: 'add_internal_note',
                context: [
                    'note_length' => mb_strlen($body),
                ],
            );
        });
    }

    public function linkOrganization(ContactForm $supportRequest, ?int $organizationId, SystemAdmin $actor): ?ActivityEvent
    {
        return DB::transaction(function () use ($supportRequest, $organizationId, $actor): ?ActivityEvent {
            $supportRequest->refresh();
            $organization = $this->resolveOrganization($organizationId);
            $before = $this->stateSnapshot($supportRequest);

            $supportRequest->forceFill([
                'organization_id' => $organization?->id,
                'last_activity_at' => now(),
            ])->save();

            return $this->recordSupportAction(
                actor: $actor,
                supportRequest: $supportRequest->refresh(),
                eventType: 'system_admin.support.organization_linked',
                action: ActivityActionEnum::Updated,
                titleKey: 'filament_actions.audit.support_organization_linked_title',
                descriptionKey: 'filament_actions.audit.support_organization_linked_description',
                before: $before,
                after: $this->stateSnapshot($supportRequest),
                operation: 'link_organization',
                context: [
                    'organization_id' => $organization?->id,
                ],
            );
        });
    }

    public function escalate(ContactForm $supportRequest, SystemAdmin $actor): ?ActivityEvent
    {
        return DB::transaction(function () use ($supportRequest, $actor): ?ActivityEvent {
            $supportRequest->refresh();
            $before = $this->stateSnapshot($supportRequest);
            $now = now();

            $supportRequest->forceFill([
                'priority' => ContactForm::PRIORITY_URGENT,
                'escalated_at' => $supportRequest->escalated_at ?? $now,
                'escalated_by_system_admin_id' => $supportRequest->escalated_by_system_admin_id ?? $actor->id,
                'last_activity_at' => $now,
            ])->save();

            return $this->recordSupportAction(
                actor: $actor,
                supportRequest: $supportRequest->refresh(),
                eventType: 'system_admin.support.escalated',
                action: ActivityActionEnum::Updated,
                titleKey: 'filament_actions.audit.support_escalated_title',
                descriptionKey: 'filament_actions.audit.support_escalated_description',
                before: $before,
                after: $this->stateSnapshot($supportRequest),
                operation: 'escalate',
            );
        });
    }

    public function replyToCustomer(ContactForm $supportRequest, string $subject, string $body, SystemAdmin $actor): ?ActivityEvent
    {
        $subject = trim($subject);
        $body = trim($body);
        $recipientEmail = trim((string) $supportRequest->email);

        if ($recipientEmail === '') {
            throw new DomainException(trans_message('support_workspace.errors.empty_recipient_email'));
        }

        if ($subject === '') {
            throw new DomainException(trans_message('support_workspace.errors.empty_reply_subject'));
        }

        if ($body === '') {
            throw new DomainException(trans_message('support_workspace.errors.empty_reply_body'));
        }

        $result = DB::transaction(function () use ($supportRequest, $subject, $body, $actor, $recipientEmail): array {
            $supportRequest->refresh();
            $before = $this->stateSnapshot($supportRequest);
            $notes = is_array($supportRequest->internal_notes) ? $supportRequest->internal_notes : [];
            $now = now();
            $recipientName = (string) $supportRequest->name;
            $requestSubject = (string) $supportRequest->subject;
            $notes[] = [
                'type' => 'customer_reply',
                'subject' => $subject,
                'body' => $body,
                'sent_to' => $recipientEmail,
                'author_system_admin_id' => (int) $actor->id,
                'author_name' => $actor->name,
                'created_at' => $now->toISOString(),
            ];

            $supportRequest->forceFill([
                'status' => ContactForm::STATUS_PROCESSING,
                'is_processed' => true,
                'processed_at' => $supportRequest->processed_at ?? $now,
                'internal_notes' => array_values($notes),
                'last_activity_at' => $now,
            ])->save();

            $event = $this->recordSupportAction(
                actor: $actor,
                supportRequest: $supportRequest->refresh(),
                eventType: 'system_admin.support.customer_replied',
                action: ActivityActionEnum::Updated,
                titleKey: 'filament_actions.audit.support_customer_replied_title',
                descriptionKey: 'filament_actions.audit.support_customer_replied_description',
                before: $before,
                after: $this->stateSnapshot($supportRequest),
                operation: 'reply_to_customer',
                context: [
                    'sent_to' => $recipientEmail,
                    'reply_subject' => $subject,
                    'reply_length' => mb_strlen($body),
                ],
            );

            return [
                'event' => $event,
                'recipient_name' => $recipientName,
                'request_subject' => $requestSubject,
            ];
        });

        Mail::to($recipientEmail)->send(new SupportTicketReplyMail(
            recipientName: (string) $result['recipient_name'],
            recipientEmail: $recipientEmail,
            requestSubject: (string) $result['request_subject'],
            subjectText: $subject,
            bodyText: $body,
            operatorName: $actor->name,
        ));

        return $result['event'] instanceof ActivityEvent ? $result['event'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function stateSnapshot(ContactForm $supportRequest): array
    {
        return [
            'organization_id' => $supportRequest->organization_id,
            'assigned_system_admin_id' => $supportRequest->assigned_system_admin_id,
            'status' => $supportRequest->status,
            'priority' => $supportRequest->priority,
            'channel' => $supportRequest->channel,
            'internal_notes_count' => is_array($supportRequest->internal_notes) ? count($supportRequest->internal_notes) : 0,
            'last_activity_at' => $supportRequest->last_activity_at?->toISOString(),
            'escalated_at' => $supportRequest->escalated_at?->toISOString(),
            'escalated_by_system_admin_id' => $supportRequest->escalated_by_system_admin_id,
            'is_processed' => $supportRequest->is_processed,
            'processed_at' => $supportRequest->processed_at?->toISOString(),
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, mixed> $context
     */
    private function recordSupportAction(
        SystemAdmin $actor,
        ContactForm $supportRequest,
        string $eventType,
        ActivityActionEnum $action,
        string $titleKey,
        string $descriptionKey,
        array $before,
        array $after,
        string $operation,
        array $context = [],
    ): ?ActivityEvent {
        $subjectLabel = $this->subjectLabel($supportRequest);

        return $this->auditService->record(
            actor: $actor,
            eventType: $eventType,
            action: $action,
            subjectType: ContactForm::class,
            subjectId: (int) $supportRequest->id,
            subjectLabel: $subjectLabel,
            organizationId: is_numeric($supportRequest->organization_id) ? (int) $supportRequest->organization_id : null,
            title: trans_message($titleKey, ['request' => $subjectLabel]),
            description: trans_message($descriptionKey, ['request' => $subjectLabel]),
            before: $before,
            after: $after,
            context: array_merge([
                'operation' => $operation,
                'support_request_id' => (int) $supportRequest->id,
            ], $context),
        );
    }

    private function subjectLabel(ContactForm $supportRequest): string
    {
        $subject = trim((string) $supportRequest->subject);

        return $subject !== '' ? $subject : sprintf('Support #%d', (int) $supportRequest->id);
    }

    private function resolveSystemAdmin(?int $systemAdminId): ?SystemAdmin
    {
        if ($systemAdminId === null) {
            return null;
        }

        $systemAdmin = SystemAdmin::query()
            ->whereKey($systemAdminId)
            ->where('is_active', true)
            ->first();

        if (! $systemAdmin instanceof SystemAdmin) {
            throw new DomainException(trans_message('support_workspace.errors.assignee_not_found'));
        }

        return $systemAdmin;
    }

    private function resolveOrganization(?int $organizationId): ?Organization
    {
        if ($organizationId === null) {
            return null;
        }

        $organization = Organization::query()->whereKey($organizationId)->first();

        if (! $organization instanceof Organization) {
            throw new DomainException(trans_message('support_workspace.errors.organization_not_found'));
        }

        return $organization;
    }
}
