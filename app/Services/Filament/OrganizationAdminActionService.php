<?php

declare(strict_types=1);

namespace App\Services\Filament;

use App\Enums\Activity\ActivityActionEnum;
use App\Models\Activity\ActivityEvent;
use App\Models\Organization;
use App\Models\SystemAdmin;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class OrganizationAdminActionService
{
    public function __construct(
        private readonly SystemAdminAuditService $auditService,
    ) {}

    public function suspend(Organization $organization, SystemAdmin $actor, ?string $reason = null): ?ActivityEvent
    {
        return DB::transaction(function () use ($organization, $actor, $reason): ?ActivityEvent {
            $organization->refresh();

            if (! $organization->is_active) {
                return null;
            }

            $before = $this->stateSnapshot($organization);

            $organization->is_active = false;
            $organization->save();

            $after = $this->stateSnapshot($organization->refresh());

            return $this->auditService->record(
                actor: $actor,
                eventType: 'system_admin.organizations.suspended',
                action: ActivityActionEnum::Updated,
                subjectType: Organization::class,
                subjectId: (int) $organization->id,
                subjectLabel: $organization->name,
                organizationId: (int) $organization->id,
                title: trans_message('filament_actions.audit.organization_suspended_title', [
                    'organization' => $organization->name,
                ]),
                description: trans_message('filament_actions.audit.organization_suspended_description', [
                    'organization' => $organization->name,
                ]),
                before: $before,
                after: $after,
                context: [
                    'operation' => 'suspend',
                    'reason' => $reason,
                ],
            );
        });
    }

    public function reactivate(Organization $organization, SystemAdmin $actor): ?ActivityEvent
    {
        return DB::transaction(function () use ($organization, $actor): ?ActivityEvent {
            $organization->refresh();

            if ($organization->is_active) {
                return null;
            }

            $before = $this->stateSnapshot($organization);

            $organization->is_active = true;
            $organization->save();

            $after = $this->stateSnapshot($organization->refresh());

            return $this->auditService->record(
                actor: $actor,
                eventType: 'system_admin.organizations.reactivated',
                action: ActivityActionEnum::Updated,
                subjectType: Organization::class,
                subjectId: (int) $organization->id,
                subjectLabel: $organization->name,
                organizationId: (int) $organization->id,
                title: trans_message('filament_actions.audit.organization_reactivated_title', [
                    'organization' => $organization->name,
                ]),
                description: trans_message('filament_actions.audit.organization_reactivated_description', [
                    'organization' => $organization->name,
                ]),
                before: $before,
                after: $after,
                context: [
                    'operation' => 'reactivate',
                ],
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function stateSnapshot(Organization $organization): array
    {
        return [
            'is_active' => $organization->is_active,
            'verification_status' => $organization->verification_status,
            'storage_used_mb' => $organization->storage_used_mb,
        ];
    }
}
