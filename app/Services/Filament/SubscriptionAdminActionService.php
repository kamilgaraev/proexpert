<?php

declare(strict_types=1);

namespace App\Services\Filament;

use App\Enums\Activity\ActivityActionEnum;
use App\Models\Activity\ActivityEvent;
use App\Models\OrganizationSubscription;
use App\Models\SystemAdmin;
use App\Services\SubscriptionModuleSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

use function trans_message;

final class SubscriptionAdminActionService
{
    public function __construct(
        private readonly SystemAdminAuditService $auditService,
        private readonly SubscriptionModuleSyncService $moduleSyncService,
    ) {}

    public function cancelAtPeriodEnd(
        OrganizationSubscription $subscription,
        SystemAdmin $actor,
        ?string $reason = null,
    ): ?ActivityEvent {
        return DB::transaction(function () use ($subscription, $actor, $reason): ?ActivityEvent {
            $subscription->refresh();

            if ($subscription->canceled_at !== null) {
                return null;
            }

            $before = $this->stateSnapshot($subscription);

            $subscription->canceled_at = now();
            $subscription->is_auto_payment_enabled = false;
            $subscription->save();

            return $this->recordSubscriptionAction(
                actor: $actor,
                subscription: $subscription->refresh(),
                eventType: 'system_admin.subscriptions.cancellation_scheduled',
                titleKey: 'filament_actions.audit.subscription_cancellation_scheduled_title',
                descriptionKey: 'filament_actions.audit.subscription_cancellation_scheduled_description',
                before: $before,
                after: $this->stateSnapshot($subscription),
                context: [
                    'operation' => 'cancel_at_period_end',
                    'reason' => $reason,
                ],
            );
        });
    }

    public function reactivate(OrganizationSubscription $subscription, SystemAdmin $actor): ?ActivityEvent
    {
        return DB::transaction(function () use ($subscription, $actor): ?ActivityEvent {
            $subscription->refresh();

            if ($subscription->canceled_at === null && $subscription->status === 'active') {
                return null;
            }

            $before = $this->stateSnapshot($subscription);

            $subscription->status = 'active';
            $subscription->canceled_at = null;
            $subscription->is_auto_payment_enabled = true;
            $subscription->save();

            $this->moduleSyncService->handleSubscriptionReactivation($subscription);

            return $this->recordSubscriptionAction(
                actor: $actor,
                subscription: $subscription->refresh(),
                eventType: 'system_admin.subscriptions.reactivated',
                titleKey: 'filament_actions.audit.subscription_reactivated_title',
                descriptionKey: 'filament_actions.audit.subscription_reactivated_description',
                before: $before,
                after: $this->stateSnapshot($subscription),
                context: [
                    'operation' => 'reactivate',
                ],
            );
        });
    }

    public function grantManualExtension(
        OrganizationSubscription $subscription,
        SystemAdmin $actor,
        int $days,
        string $reason,
    ): ?ActivityEvent {
        if ($days < 1) {
            throw new InvalidArgumentException(trans_message('filament_actions.subscription.extension.invalid_days'));
        }

        return DB::transaction(function () use ($subscription, $actor, $days, $reason): ?ActivityEvent {
            $subscription->refresh();
            $before = $this->stateSnapshot($subscription);
            $baseEndsAt = $subscription->ends_at instanceof Carbon && $subscription->ends_at->isFuture()
                ? $subscription->ends_at->copy()
                : now();
            $newEndsAt = $baseEndsAt->copy()->addDays($days);
            $subscription->ends_at = $newEndsAt;
            $subscription->next_billing_at = $newEndsAt;
            $subscription->save();
            $subscription->syncModulesExpiration();

            return $this->recordSubscriptionAction(
                actor: $actor,
                subscription: $subscription->refresh(),
                eventType: 'system_admin.subscriptions.manual_extension_granted',
                titleKey: 'filament_actions.audit.subscription_manual_extension_granted_title',
                descriptionKey: 'filament_actions.audit.subscription_manual_extension_granted_description',
                before: $before,
                after: $this->stateSnapshot($subscription),
                context: [
                    'operation' => 'grant_manual_extension',
                    'days' => $days,
                    'reason' => $reason,
                ],
            );
        });
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $context
     */
    private function recordSubscriptionAction(
        SystemAdmin $actor,
        OrganizationSubscription $subscription,
        string $eventType,
        string $titleKey,
        string $descriptionKey,
        array $before,
        array $after,
        array $context,
    ): ?ActivityEvent {
        $label = sprintf(
            '%s #%d',
            $subscription->plan?->name ?? trans_message('widgets.subscriptions.model_label'),
            (int) $subscription->id,
        );

        return $this->auditService->record(
            actor: $actor,
            eventType: $eventType,
            action: ActivityActionEnum::Updated,
            subjectType: OrganizationSubscription::class,
            subjectId: (int) $subscription->id,
            subjectLabel: $label,
            organizationId: (int) $subscription->organization_id,
            title: trans_message($titleKey, ['subscription' => $label]),
            description: trans_message($descriptionKey, ['subscription' => $label]),
            before: $before,
            after: $after,
            context: $context,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function stateSnapshot(OrganizationSubscription $subscription): array
    {
        return [
            'organization_id' => $subscription->organization_id,
            'subscription_plan_id' => $subscription->subscription_plan_id,
            'status' => $subscription->status,
            'trial_ends_at' => $subscription->trial_ends_at?->toISOString(),
            'starts_at' => $subscription->starts_at?->toISOString(),
            'ends_at' => $subscription->ends_at?->toISOString(),
            'next_billing_at' => $subscription->next_billing_at?->toISOString(),
            'canceled_at' => $subscription->canceled_at?->toISOString(),
            'is_auto_payment_enabled' => $subscription->is_auto_payment_enabled,
        ];
    }
}
