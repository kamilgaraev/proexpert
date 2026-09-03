<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentNotificationDelivery;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep;
use App\BusinessModules\Features\Notifications\Models\Notification as DatabaseNotification;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\Models\User;
use App\Notifications\LegalArchive\LegalDocumentApprovalRequiredNotification;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Workflow\LegalWorkflowActorResolver;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LegalDocumentNotificationPublisher
{
    public function __construct(private readonly ?NotificationService $notificationService = null) {}

    public function publish(
        LegalArchiveDocument $document,
        User $recipient,
        string $key,
        Notification $notification,
    ): void {
        $delivery = $this->claim(
            $document,
            $recipient,
            $key,
            $notification,
            $notification->toArray($recipient),
        );

        if ($delivery === null) {
            return;
        }

        $this->persistClaimed($delivery, $recipient);
    }

    public function persistClaimed(LegalDocumentNotificationDelivery $delivery, User $recipient): bool
    {
        return $this->persist($delivery, $recipient);
    }

    private function claim(
        LegalArchiveDocument $document,
        User $recipient,
        string $key,
        Notification $notification,
        array $payload,
    ): ?LegalDocumentNotificationDelivery {
        $token = Str::random(64);

        try {
            return DB::transaction(function () use ($document, $recipient, $key, $notification, $payload, $token): ?LegalDocumentNotificationDelivery {
                $delivery = LegalDocumentNotificationDelivery::query()
                    ->where([
                        'document_id' => $document->id,
                        'recipient_user_id' => $recipient->id,
                        'delivery_key' => $key,
                    ])
                    ->lockForUpdate()
                    ->first();

                if ($delivery?->status === 'delivered'
                    || $delivery?->lease_expires_at?->isFuture()) {
                    return null;
                }

                $data = [
                    'status' => 'sending',
                    'notification_id' => $delivery?->notification_id ?? (string) Str::uuid(),
                    'notification_type' => $notification::class,
                    'notification_payload' => $payload,
                    'lease_token' => hash('sha256', $token),
                    'lease_expires_at' => now()->addMinutes(5),
                    'attempt_count' => ((int) ($delivery?->attempt_count ?? 0)) + 1,
                ];

                if ($delivery === null) {
                    return LegalDocumentNotificationDelivery::query()->create([
                        'document_id' => $document->id,
                        'recipient_user_id' => $recipient->id,
                        'delivery_key' => $key,
                        ...$data,
                    ]);
                }

                $delivery->forceFill($data)->save();

                return $delivery;
            });
        } catch (QueryException) {
            return null;
        }
    }

    private function persist(LegalDocumentNotificationDelivery $delivery, User $recipient): bool
    {
        return DB::transaction(function () use ($delivery, $recipient): bool {
            $document = $delivery->document()->lockForUpdate()->first();
            $locked = LegalDocumentNotificationDelivery::query()
                ->whereKey($delivery->id)
                ->where('lease_token', $delivery->lease_token)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof LegalDocumentNotificationDelivery || $locked->status === 'delivered') {
                return false;
            }

            if ((int) $locked->recipient_user_id !== (int) $recipient->id) {
                throw new DomainException('notification_recipient_conflict');
            }

            try {
                if (! $document instanceof LegalArchiveDocument) {
                    throw new AuthorizationException;
                }
                $recipient = clone $recipient;
                if ($document->getConnection()->table('organization_user')
                    ->where('organization_id', (int) $document->organization_id)
                    ->where('user_id', (int) $recipient->id)
                    ->where('is_active', true)
                    ->exists()) {
                    $recipient->setAttribute('current_organization_id', (int) $document->organization_id);
                }
                $access = app(LegalDocumentAuthorizer::class);
                $access->authorize($recipient, $document, 'view');
                if ($locked->notification_type === LegalDocumentApprovalRequiredNotification::class) {
                    $access->authorize($recipient, $document, 'approve');
                    $this->assertCurrentWorkflowAssignment($locked, $document, $recipient);
                }
            } catch (AuthorizationException) {
                $locked->forceFill([
                    'status' => 'discarded',
                    'lease_expires_at' => null,
                    'lease_token' => null,
                ])->save();

                return false;
            }

            $notification = DatabaseNotification::query()->find($locked->notification_id);
            if ($notification instanceof DatabaseNotification && (
                $notification->notifiable_type !== User::class
                || (int) $notification->notifiable_id !== (int) $recipient->id
                || (int) $notification->organization_id !== (int) $document->organization_id
                || $notification->type !== $locked->notification_type
            )) {
                throw new DomainException('notification_identifier_conflict');
            }
            if (! $notification instanceof DatabaseNotification || ! $notification->targets()->exists()) {
                $notification = ($this->notificationService ?? app(NotificationService::class))->sendWithId(
                    (string) $locked->notification_id,
                    $recipient,
                    (string) $locked->notification_type,
                    (array) $locked->notification_payload,
                    notificationType: 'legal_archive',
                    channels: ['in_app', 'websocket'],
                    organizationId: (int) $document->organization_id,
                    interfaces: ['admin'],
                );
                if (! $notification->exists) {
                    return false;
                }
            }

            $locked->forceFill([
                'status' => 'delivered',
                'delivered_at' => now(),
                'lease_expires_at' => null,
                'lease_token' => null,
            ])->save();

            return true;
        });
    }

    private function assertCurrentWorkflowAssignment(
        LegalDocumentNotificationDelivery $delivery,
        LegalArchiveDocument $document,
        User $recipient,
    ): void {
        $payload = (array) $delivery->notification_payload;
        $stepId = $payload['workflow_step_id'] ?? null;
        $revision = (int) ($payload['workflow_assignment_revision'] ?? 0);
        $actorType = (string) ($payload['workflow_actor_type'] ?? 'user');
        $actorReference = (string) ($payload['workflow_actor_reference'] ?? $recipient->id);
        if ($stepId === null && preg_match('/^workflow-step:(\d+):(\d+)(?::assignment:(\d+))?$/D', (string) $delivery->delivery_key, $matches) === 1) {
            $stepId = (int) $matches[1];
            $actorReference = $matches[2];
            $revision = (int) ($matches[3] ?? 0);
        }
        if ($stepId === null) {
            return;
        }

        $step = (new LegalWorkflowStep)->setConnection($document->getConnectionName())->newQuery()
            ->whereKey((int) $stepId)
            ->where('organization_id', (int) $document->organization_id)
            ->where('status', 'active')
            ->where('actor_type', $actorType)
            ->where('actor_reference', $actorReference)
            ->where('assignment_revision', $revision)
            ->whereHas('instance', static fn ($query) => $query
                ->where('document_id', (int) $document->id)
                ->where('organization_id', (int) $document->organization_id)
                ->where('status', 'in_progress'))
            ->lockForUpdate()
            ->first();
        if (! $step instanceof LegalWorkflowStep || ! (new LegalWorkflowActorResolver)->canAct($recipient, $step, $document)) {
            throw new AuthorizationException;
        }
    }
}
