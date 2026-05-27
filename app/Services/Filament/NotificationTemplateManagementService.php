<?php

declare(strict_types=1);

namespace App\Services\Filament;

use App\BusinessModules\Features\Notifications\Models\NotificationTemplate;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\BusinessModules\Features\Notifications\Services\TemplateRenderer;
use App\Enums\Activity\ActivityActionEnum;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Notifications\SystemAdminTemplatePreviewNotification;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class NotificationTemplateManagementService
{
    private const AUDIT_RECIPIENT_SAMPLE_LIMIT = 20;

    public function __construct(
        private readonly TemplateRenderer $templateRenderer,
        private readonly NotificationService $notificationService,
        private readonly SystemAdminAuditService $auditService,
    ) {
    }

    public function preview(NotificationTemplate $template, SystemAdmin $systemAdmin, array $sampleData = []): array
    {
        $data = array_replace_recursive($this->sampleData($systemAdmin), $sampleData);
        $subject = $template->subject
            ? $this->templateRenderer->renderString((string) $template->subject, $data)
            : $template->name;

        return [
            'template_name' => $template->name,
            'type' => $template->type,
            'channel' => $template->channel,
            'subject' => $subject,
            'content' => $this->templateRenderer->render($template, $data),
            'sample_data' => $data,
        ];
    }

    public function sendTest(NotificationTemplate $template, SystemAdmin $systemAdmin): void
    {
        $preview = $this->preview($template, $systemAdmin);

        $systemAdmin->notify(new SystemAdminTemplatePreviewNotification(
            subject: (string) $preview['subject'],
            content: (string) $preview['content'],
            templateName: (string) $preview['template_name'],
        ));
    }

    public function sendToUsers(NotificationTemplate $template, SystemAdmin $systemAdmin, array $userIds): array
    {
        $normalizedUserIds = $this->normalizeUserIds($userIds);

        if ($normalizedUserIds === []) {
            throw new DomainException(trans_message('notifications.broadcast_recipients_required'));
        }

        $users = $this->activeRecipientQuery($template)
            ->with('currentOrganization')
            ->whereIn('id', $normalizedUserIds)
            ->orderBy('id')
            ->get();

        $result = $this->sendToUserCollection($template, $systemAdmin, $users);
        $this->recordBroadcastAudit($template, $systemAdmin, $result, 'selected_users');

        return $result;
    }

    public function sendToAllUsers(NotificationTemplate $template, SystemAdmin $systemAdmin): array
    {
        $sentCount = 0;
        $recipientSampleIds = [];
        $recipientIdsHash = hash_init('sha256');
        $hasRecipients = false;

        $this->activeRecipientQuery($template)
            ->with('currentOrganization')
            ->orderBy('id')
            ->chunkById(500, function (Collection $users) use (
                $template,
                $systemAdmin,
                &$sentCount,
                &$recipientSampleIds,
                $recipientIdsHash,
                &$hasRecipients,
            ): void {
                $result = $this->sendToUserCollection($template, $systemAdmin, $users);
                $sentCount += (int) $result['sent_count'];

                foreach ($result['recipient_ids'] as $recipientId) {
                    if ($hasRecipients) {
                        hash_update($recipientIdsHash, ',');
                    }

                    hash_update($recipientIdsHash, (string) $recipientId);
                    $hasRecipients = true;

                    if (count($recipientSampleIds) < self::AUDIT_RECIPIENT_SAMPLE_LIMIT) {
                        $recipientSampleIds[] = (int) $recipientId;
                    }
                }
            });

        $result = [
            'sent_count' => $sentCount,
            'recipient_sample_ids' => $recipientSampleIds,
            'recipient_sample_count' => count($recipientSampleIds),
            'omitted_recipient_count' => max(0, $sentCount - count($recipientSampleIds)),
            'recipient_ids_hash' => hash_final($recipientIdsHash),
        ];
        $this->recordBroadcastAudit($template, $systemAdmin, $result, 'all_users');

        return $result;
    }

    private function activeRecipientQuery(NotificationTemplate $template): Builder
    {
        $query = User::query()
            ->where('is_active', true);
        $organizationId = $this->templateOrganizationId($template);

        if ($organizationId !== null) {
            $query->whereHas('organizations', function (Builder $organizationQuery) use ($organizationId): void {
                $organizationQuery
                    ->where('organizations.id', $organizationId)
                    ->where('organization_user.is_active', true);
            });
        }

        return $query;
    }

    private function sendToUserCollection(NotificationTemplate $template, SystemAdmin $systemAdmin, Collection $users): array
    {
        $recipientIds = [];

        $template->loadMissing('organization');

        foreach ($users as $user) {
            if (! $user instanceof User) {
                continue;
            }

            $data = $this->notificationData($template, $systemAdmin, $user);
            $organizationId = is_numeric($template->organization_id)
                ? (int) $template->organization_id
                : (is_numeric($user->current_organization_id) ? (int) $user->current_organization_id : null);

            $this->notificationService->send(
                user: $user,
                type: (string) $template->type,
                data: $data,
                notificationType: 'system_admin_broadcast',
                priority: 'normal',
                channels: [(string) $template->channel],
                organizationId: $organizationId,
            );

            $recipientIds[] = (int) $user->id;
        }

        return [
            'sent_count' => count($recipientIds),
            'recipient_ids' => $recipientIds,
        ];
    }

    private function templateOrganizationId(NotificationTemplate $template): ?int
    {
        return is_numeric($template->organization_id) ? (int) $template->organization_id : null;
    }

    private function notificationData(NotificationTemplate $template, SystemAdmin $systemAdmin, User $user): array
    {
        $data = $this->sampleData($systemAdmin, $user, $template);
        $subject = $template->subject
            ? $this->templateRenderer->renderString((string) $template->subject, $data)
            : $template->name;

        return [
            ...$data,
            'title' => (string) $subject,
            'message' => $this->templateRenderer->render($template, $data),
            'template_id' => $template->id,
            'template_name' => $template->name,
            'interface' => 'customer',
        ];
    }

    private function normalizeUserIds(array $userIds): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $userId): int => is_numeric($userId) ? (int) $userId : 0,
            $userIds,
        ), static fn (int $userId): bool => $userId > 0)));
    }

    private function recordBroadcastAudit(
        NotificationTemplate $template,
        SystemAdmin $systemAdmin,
        array $result,
        string $audience,
    ): void {
        $recipientSummary = $this->recipientAuditSummary($result);

        $this->auditService->record(
            actor: $systemAdmin,
            eventType: 'system_admin.notifications.broadcast_sent',
            action: ActivityActionEnum::Created,
            subjectType: NotificationTemplate::class,
            subjectId: is_numeric($template->id) ? (int) $template->id : null,
            subjectLabel: (string) $template->name,
            organizationId: is_numeric($template->organization_id) ? (int) $template->organization_id : null,
            title: trans_message('filament_actions.audit.notification_broadcast_sent_title', [
                'template' => (string) $template->name,
            ]),
            description: trans_message('filament_actions.audit.notification_broadcast_sent_description', [
                'template' => (string) $template->name,
                'count' => (int) ($result['sent_count'] ?? 0),
            ]),
            after: [
                'sent_count' => (int) ($result['sent_count'] ?? 0),
                ...$recipientSummary,
            ],
            context: [
                'operation' => 'notification_broadcast',
                'audience' => $audience,
                'template_type' => (string) $template->type,
                'template_channel' => (string) $template->channel,
            ],
        );
    }

    private function recipientAuditSummary(array $result): array
    {
        $recipientIds = $this->normalizeUserIds((array) ($result['recipient_ids'] ?? []));
        $sampleIds = $this->normalizeUserIds((array) ($result['recipient_sample_ids'] ?? []));
        $sentCount = (int) ($result['sent_count'] ?? count($recipientIds));

        if ($sampleIds === [] && $recipientIds !== []) {
            $sampleIds = array_slice($recipientIds, 0, self::AUDIT_RECIPIENT_SAMPLE_LIMIT);
        }

        $recipientIdsHash = (string) ($result['recipient_ids_hash'] ?? '');

        if ($recipientIdsHash === '' && $recipientIds !== []) {
            $recipientIdsHash = hash('sha256', implode(',', $recipientIds));
        }

        if ($recipientIdsHash === '') {
            $recipientIdsHash = hash('sha256', '');
        }

        return [
            'recipient_sample_ids' => $sampleIds,
            'recipient_sample_count' => count($sampleIds),
            'omitted_recipient_count' => max(0, $sentCount - count($sampleIds)),
            'recipient_ids_hash' => $recipientIdsHash,
        ];
    }

    private function sampleData(SystemAdmin $systemAdmin, ?User $user = null, ?NotificationTemplate $template = null): array
    {
        $organization = $template?->organization ?? $user?->currentOrganization;

        return [
            'system_admin' => [
                'id' => $systemAdmin->id,
                'name' => $systemAdmin->name,
                'email' => $systemAdmin->email,
                'role' => $systemAdmin->getRoleName(),
            ],
            'user' => [
                'id' => $user?->id ?? 1,
                'name' => $user?->name ?? $systemAdmin->name,
                'email' => $user?->email ?? $systemAdmin->email,
            ],
            'organization' => [
                'id' => $organization?->id ?? 1,
                'name' => $organization?->name ?? 'ProHelper Demo',
            ],
            'project' => [
                'id' => 1,
                'name' => 'Demo project',
                'number' => 'PRJ-001',
            ],
            'system' => [
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
                'support_email' => config('mail.from.address'),
            ],
        ];
    }
}
