<?php

declare(strict_types=1);

namespace App\Services\Filament;

use App\Enums\Activity\ActivityActionEnum;
use App\Models\Activity\ActivityEvent;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Services\Auth\JwtAuthService;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class UserAdminActionService
{
    public function __construct(
        private readonly SystemAdminAuditService $auditService,
        private readonly JwtAuthService $authService,
    ) {}

    public function block(User $user, SystemAdmin $actor): ?ActivityEvent
    {
        return DB::transaction(function () use ($user, $actor): ?ActivityEvent {
            $user->refresh();

            if (! $user->is_active) {
                return null;
            }

            $before = $this->stateSnapshot($user);

            $user->is_active = false;
            $user->save();

            return $this->recordUserAction(
                actor: $actor,
                user: $user->refresh(),
                eventType: 'system_admin.users.blocked',
                titleKey: 'filament_actions.audit.user_blocked_title',
                descriptionKey: 'filament_actions.audit.user_blocked_description',
                before: $before,
                after: $this->stateSnapshot($user),
                operation: 'block',
            );
        });
    }

    public function unblock(User $user, SystemAdmin $actor): ?ActivityEvent
    {
        return DB::transaction(function () use ($user, $actor): ?ActivityEvent {
            $user->refresh();

            if ($user->is_active) {
                return null;
            }

            $before = $this->stateSnapshot($user);

            $user->is_active = true;
            $user->save();

            return $this->recordUserAction(
                actor: $actor,
                user: $user->refresh(),
                eventType: 'system_admin.users.unblocked',
                titleKey: 'filament_actions.audit.user_unblocked_title',
                descriptionKey: 'filament_actions.audit.user_unblocked_description',
                before: $before,
                after: $this->stateSnapshot($user),
                operation: 'unblock',
            );
        });
    }

    public function markEmailVerified(User $user, SystemAdmin $actor): ?ActivityEvent
    {
        return DB::transaction(function () use ($user, $actor): ?ActivityEvent {
            $user->refresh();

            if ($user->email_verified_at !== null) {
                return null;
            }

            $before = $this->stateSnapshot($user);

            $user->email_verified_at = now();
            $user->save();

            return $this->recordUserAction(
                actor: $actor,
                user: $user->refresh(),
                eventType: 'system_admin.users.email_verified',
                titleKey: 'filament_actions.audit.user_email_verified_title',
                descriptionKey: 'filament_actions.audit.user_email_verified_description',
                before: $before,
                after: $this->stateSnapshot($user),
                operation: 'mark_email_verified',
            );
        });
    }

    public function sendPasswordReset(User $user, SystemAdmin $actor): ?ActivityEvent
    {
        $this->authService->sendResetLink($user->email);

        return $this->recordUserAction(
            actor: $actor,
            user: $user->refresh(),
            eventType: 'system_admin.users.password_reset_sent',
            titleKey: 'filament_actions.audit.user_password_reset_sent_title',
            descriptionKey: 'filament_actions.audit.user_password_reset_sent_description',
            before: $this->stateSnapshot($user),
            after: $this->stateSnapshot($user),
            operation: 'send_password_reset',
        );
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function recordUserAction(
        SystemAdmin $actor,
        User $user,
        string $eventType,
        string $titleKey,
        string $descriptionKey,
        array $before,
        array $after,
        string $operation,
    ): ?ActivityEvent {
        return $this->auditService->record(
            actor: $actor,
            eventType: $eventType,
            action: ActivityActionEnum::Updated,
            subjectType: User::class,
            subjectId: (int) $user->id,
            subjectLabel: $user->email,
            organizationId: is_numeric($user->current_organization_id) ? (int) $user->current_organization_id : null,
            title: trans_message($titleKey, ['user' => $user->email]),
            description: trans_message($descriptionKey, ['user' => $user->email]),
            before: $before,
            after: $after,
            context: [
                'operation' => $operation,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function stateSnapshot(User $user): array
    {
        return [
            'is_active' => $user->is_active,
            'email_verified_at' => $user->email_verified_at?->toISOString(),
            'current_organization_id' => $user->current_organization_id,
            'last_login_at' => $user->last_login_at?->toISOString(),
        ];
    }
}
