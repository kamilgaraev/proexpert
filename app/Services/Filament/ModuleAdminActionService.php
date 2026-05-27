<?php

declare(strict_types=1);

namespace App\Services\Filament;

use App\Enums\Activity\ActivityActionEnum;
use App\Models\Activity\ActivityEvent;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use App\Models\SystemAdmin;
use App\Modules\Core\AccessController;
use App\Services\SubscriptionModuleSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

use function trans_message;

final class ModuleAdminActionService
{
    public function __construct(
        private readonly SystemAdminAuditService $auditService,
        private readonly SubscriptionModuleSyncService $subscriptionModuleSyncService,
        private readonly AccessController $accessController,
    ) {}

    public function enableForOrganization(
        Organization $organization,
        Module $module,
        SystemAdmin $actor,
        string $reason,
        ?int $trialDays = null,
    ): OrganizationModuleActivation {
        $this->assertModuleCanBeActivated($module);

        if ($trialDays !== null && $trialDays < 1) {
            throw new InvalidArgumentException(trans_message('filament_actions.module.invalid_days'));
        }

        return DB::transaction(function () use ($organization, $module, $actor, $reason, $trialDays): OrganizationModuleActivation {
            $existing = OrganizationModuleActivation::query()
                ->where('organization_id', $organization->id)
                ->where('module_id', $module->id)
                ->first();
            $before = $existing instanceof OrganizationModuleActivation ? $this->activationSnapshot($existing) : [];
            $warnings = $this->accessController->checkDependencies((int) $organization->id, $module);
            $expiresAt = $trialDays !== null ? now()->addDays($trialDays) : null;

            $activation = OrganizationModuleActivation::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'module_id' => $module->id,
                ],
                [
                    'subscription_id' => null,
                    'is_bundled_with_plan' => false,
                    'status' => $trialDays !== null ? 'trial' : 'active',
                    'activated_at' => $existing?->activated_at ?? now(),
                    'expires_at' => $expiresAt,
                    'trial_ends_at' => $trialDays !== null ? $expiresAt : null,
                    'cancelled_at' => null,
                    'cancellation_reason' => null,
                    'paid_amount' => $module->getPrice(),
                    'next_billing_date' => $expiresAt,
                    'module_settings' => $existing?->module_settings ?? [],
                    'is_auto_renew_enabled' => $trialDays === null,
                ],
            );

            $activation->refresh();
            $this->accessController->clearAccessCache((int) $organization->id);

            $this->recordActivationAction(
                actor: $actor,
                activation: $activation,
                eventType: 'system_admin.modules.enabled',
                titleKey: 'filament_actions.audit.module_enabled_title',
                descriptionKey: 'filament_actions.audit.module_enabled_description',
                before: $before,
                after: $this->activationSnapshot($activation),
                context: [
                    'operation' => 'enable_for_organization',
                    'reason' => $reason,
                    'dependency_warnings' => $warnings,
                    'trial_days' => $trialDays,
                ],
            );

            return $activation;
        });
    }

    public function enable(
        OrganizationModuleActivation $activation,
        SystemAdmin $actor,
        string $reason,
    ): ?ActivityEvent {
        $activation->loadMissing(['organization', 'module']);

        if (! $activation->module instanceof Module) {
            return null;
        }

        $this->assertModuleCanBeActivated($activation->module);

        return DB::transaction(function () use ($activation, $actor, $reason): ?ActivityEvent {
            $activation->refresh();

            if ($activation->status === 'active' && $activation->cancelled_at === null) {
                return null;
            }

            $before = $this->activationSnapshot($activation);
            $activation->update([
                'status' => 'active',
                'activated_at' => $activation->activated_at ?? now(),
                'trial_ends_at' => null,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'is_auto_renew_enabled' => true,
            ]);
            $activation->refresh();
            $this->accessController->clearAccessCache((int) $activation->organization_id);

            return $this->recordActivationAction(
                actor: $actor,
                activation: $activation,
                eventType: 'system_admin.modules.enabled',
                titleKey: 'filament_actions.audit.module_enabled_title',
                descriptionKey: 'filament_actions.audit.module_enabled_description',
                before: $before,
                after: $this->activationSnapshot($activation),
                context: [
                    'operation' => 'enable_activation',
                    'reason' => $reason,
                ],
            );
        });
    }

    public function disable(
        OrganizationModuleActivation $activation,
        SystemAdmin $actor,
        string $reason,
    ): ?ActivityEvent {
        return DB::transaction(function () use ($activation, $actor, $reason): ?ActivityEvent {
            $activation->refresh();

            if ($activation->status === 'suspended' && $activation->cancelled_at !== null) {
                return null;
            }

            $before = $this->activationSnapshot($activation);
            $activation->update([
                'status' => 'suspended',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'is_auto_renew_enabled' => false,
            ]);
            $activation->refresh();
            $this->accessController->clearAccessCache((int) $activation->organization_id);

            return $this->recordActivationAction(
                actor: $actor,
                activation: $activation,
                eventType: 'system_admin.modules.disabled',
                titleKey: 'filament_actions.audit.module_disabled_title',
                descriptionKey: 'filament_actions.audit.module_disabled_description',
                before: $before,
                after: $this->activationSnapshot($activation),
                context: [
                    'operation' => 'disable_activation',
                    'reason' => $reason,
                ],
            );
        });
    }

    public function startTrial(
        OrganizationModuleActivation $activation,
        SystemAdmin $actor,
        int $days,
        string $reason,
    ): ?ActivityEvent {
        if ($days < 1) {
            throw new InvalidArgumentException(trans_message('filament_actions.module.invalid_days'));
        }

        return DB::transaction(function () use ($activation, $actor, $days, $reason): ?ActivityEvent {
            $activation->refresh();
            $before = $this->activationSnapshot($activation);
            $trialEndsAt = now()->addDays($days);

            $activation->update([
                'status' => 'trial',
                'activated_at' => $activation->activated_at ?? now(),
                'expires_at' => $trialEndsAt,
                'trial_ends_at' => $trialEndsAt,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'is_auto_renew_enabled' => false,
            ]);
            $activation->refresh();
            $this->accessController->clearAccessCache((int) $activation->organization_id);

            return $this->recordActivationAction(
                actor: $actor,
                activation: $activation,
                eventType: 'system_admin.modules.trial_started',
                titleKey: 'filament_actions.audit.module_trial_started_title',
                descriptionKey: 'filament_actions.audit.module_trial_started_description',
                before: $before,
                after: $this->activationSnapshot($activation),
                context: [
                    'operation' => 'start_trial',
                    'days' => $days,
                    'reason' => $reason,
                ],
            );
        });
    }

    public function extendAccess(
        OrganizationModuleActivation $activation,
        SystemAdmin $actor,
        int $days,
        string $reason,
    ): ?ActivityEvent {
        if ($days < 1) {
            throw new InvalidArgumentException(trans_message('filament_actions.module.invalid_days'));
        }

        return DB::transaction(function () use ($activation, $actor, $days, $reason): ?ActivityEvent {
            $activation->refresh();
            $before = $this->activationSnapshot($activation);
            $baseExpiresAt = $activation->expires_at instanceof Carbon && $activation->expires_at->isFuture()
                ? $activation->expires_at->copy()
                : now();
            $newExpiresAt = $baseExpiresAt->copy()->addDays($days);

            $activation->update([
                'status' => 'active',
                'expires_at' => $newExpiresAt,
                'trial_ends_at' => null,
                'next_billing_date' => $newExpiresAt,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'is_auto_renew_enabled' => true,
            ]);
            $activation->refresh();
            $this->accessController->clearAccessCache((int) $activation->organization_id);

            return $this->recordActivationAction(
                actor: $actor,
                activation: $activation,
                eventType: 'system_admin.modules.access_extended',
                titleKey: 'filament_actions.audit.module_access_extended_title',
                descriptionKey: 'filament_actions.audit.module_access_extended_description',
                before: $before,
                after: $this->activationSnapshot($activation),
                context: [
                    'operation' => 'extend_access',
                    'days' => $days,
                    'reason' => $reason,
                ],
            );
        });
    }

    public function syncEntitlements(Organization $organization, SystemAdmin $actor): ?ActivityEvent
    {
        return DB::transaction(function () use ($organization, $actor): ?ActivityEvent {
            $beforeCounts = $this->organizationEntitlementCounts($organization);
            $subscriptionSync = $this->subscriptionModuleSyncService->ensureBundledModulesSyncedForOrganization((int) $organization->id);
            $packageRepair = $this->subscriptionModuleSyncService->repairPackageModuleActivationsForOrganization((int) $organization->id);
            $this->accessController->clearAccessCache((int) $organization->id);
            $organization->refresh();

            return $this->auditService->record(
                actor: $actor,
                eventType: 'system_admin.modules.entitlements_synced',
                action: ActivityActionEnum::Updated,
                subjectType: Organization::class,
                subjectId: (int) $organization->id,
                subjectLabel: (string) $organization->name,
                organizationId: (int) $organization->id,
                title: trans_message('filament_actions.audit.module_entitlements_synced_title', ['organization' => $organization->name]),
                description: trans_message('filament_actions.audit.module_entitlements_synced_description', ['organization' => $organization->name]),
                before: $beforeCounts,
                after: $this->organizationEntitlementCounts($organization),
                context: [
                    'operation' => 'sync_entitlements',
                    'subscription_sync' => $subscriptionSync,
                    'package_repair' => $packageRepair,
                ],
            );
        });
    }

    private function assertModuleCanBeActivated(Module $module): void
    {
        if (! $module->is_active) {
            throw new InvalidArgumentException(trans_message('filament_actions.module.inactive_module'));
        }

        if (! $module->canBeActivatedByStatus()) {
            throw new InvalidArgumentException(trans_message('filament_actions.module.unavailable_module'));
        }
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, mixed> $context
     */
    private function recordActivationAction(
        SystemAdmin $actor,
        OrganizationModuleActivation $activation,
        string $eventType,
        string $titleKey,
        string $descriptionKey,
        array $before,
        array $after,
        array $context,
    ): ?ActivityEvent {
        $activation->loadMissing(['organization', 'module']);
        $organizationName = $activation->organization?->name ?? trans_message('widgets.modules.organization');
        $moduleName = $activation->module?->name ?? trans_message('widgets.modules.model_label');
        $label = sprintf('%s / %s', $organizationName, $moduleName);

        return $this->auditService->record(
            actor: $actor,
            eventType: $eventType,
            action: ActivityActionEnum::Updated,
            subjectType: OrganizationModuleActivation::class,
            subjectId: (int) $activation->id,
            subjectLabel: $label,
            organizationId: (int) $activation->organization_id,
            title: trans_message($titleKey, ['module' => $moduleName, 'organization' => $organizationName]),
            description: trans_message($descriptionKey, ['module' => $moduleName, 'organization' => $organizationName]),
            before: $before,
            after: $after,
            context: $context,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function activationSnapshot(OrganizationModuleActivation $activation): array
    {
        return [
            'organization_id' => $activation->organization_id,
            'module_id' => $activation->module_id,
            'subscription_id' => $activation->subscription_id,
            'is_bundled_with_plan' => $activation->is_bundled_with_plan,
            'status' => $activation->status,
            'activated_at' => $activation->activated_at?->toISOString(),
            'expires_at' => $activation->expires_at?->toISOString(),
            'trial_ends_at' => $activation->trial_ends_at?->toISOString(),
            'cancelled_at' => $activation->cancelled_at?->toISOString(),
            'cancellation_reason' => $activation->cancellation_reason,
            'next_billing_date' => $activation->next_billing_date?->toISOString(),
            'is_auto_renew_enabled' => $activation->is_auto_renew_enabled,
            'module_settings' => $activation->module_settings,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function organizationEntitlementCounts(Organization $organization): array
    {
        return [
            'module_activations' => OrganizationModuleActivation::query()
                ->where('organization_id', $organization->id)
                ->count(),
            'active_module_activations' => OrganizationModuleActivation::query()
                ->where('organization_id', $organization->id)
                ->where('status', 'active')
                ->count(),
        ];
    }
}
