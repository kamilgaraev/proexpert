<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\Services;

use App\Services\Customer\Reporting\Sla\Enums\CustomerActorSide;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class HistoricalCustomerActorSideResolver
{
    public function __construct(private CustomerActorSideResolver $actorSides) {}

    public function customerOrganizationId(
        ?int $projectId,
        CarbonImmutable $occurredAt,
    ): ?int {
        if ($projectId === null) {
            return null;
        }

        $customers = DB::table('project_organization')
            ->where('project_id', $projectId)
            ->whereNotNull('accepted_at')
            ->where('accepted_at', '<=', $occurredAt)
            ->get()
            ->filter(fn (object $participant): bool => $this->projectCustomerMembershipValidAt(
                $participant,
                $occurredAt,
            ))
            ->pluck('organization_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        return $customers->count() === 1 ? $customers->first() : null;
    }

    public function resolve(
        int $ownerOrganizationId,
        ?int $customerOrganizationId,
        int $actorId,
        CarbonImmutable $occurredAt,
    ): CustomerActorSide {
        $organizationIds = DB::table('organization_user')
            ->where('user_id', $actorId)
            ->where('created_at', '<=', $occurredAt)
            ->get()
            ->filter(fn (object $membership): bool => $this->membershipValidAt(
                'organization_user',
                $membership,
                $occurredAt,
            ))
            ->pluck('organization_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $this->actorSides->resolve(
            $ownerOrganizationId,
            $customerOrganizationId,
            $organizationIds,
        );
    }

    private function membershipValidAt(
        string $kind,
        object $membership,
        CarbonImmutable $occurredAt,
    ): bool {
        $updatedAt = CarbonImmutable::parse((string) $membership->updated_at);
        if ($updatedAt <= $occurredAt) {
            return (bool) $membership->is_active;
        }
        $historical = DB::table('customer_membership_history')
            ->where('membership_kind', $kind)
            ->where('membership_id', (int) $membership->id)
            ->where('valid_from', '<=', $occurredAt)
            ->where('valid_to', '>', $occurredAt)
            ->orderByDesc('valid_from')
            ->first();
        if (is_object($historical)) {
            return (bool) $historical->is_active;
        }

        $rawHistory = $membership->settings ?? $membership->metadata ?? null;
        $settings = is_string($rawHistory)
            ? json_decode($rawHistory, true)
            : $rawHistory;
        $history = is_array($settings) && is_array($settings['membership_history'] ?? null)
            ? $settings['membership_history']
            : [];
        foreach ($history as $interval) {
            if (
                ! is_array($interval)
                || ! is_string($interval['from'] ?? null)
                || (isset($interval['to']) && ! is_string($interval['to']))
            ) {
                continue;
            }
            $from = CarbonImmutable::parse($interval['from']);
            $to = isset($interval['to']) ? CarbonImmutable::parse($interval['to']) : null;
            if ($from <= $occurredAt && ($to === null || $to > $occurredAt)) {
                return true;
            }
        }

        return false;
    }

    private function projectCustomerMembershipValidAt(
        object $membership,
        CarbonImmutable $occurredAt,
    ): bool {
        $updatedAt = CarbonImmutable::parse((string) $membership->updated_at);
        if ($updatedAt <= $occurredAt) {
            $role = $membership->role_new ?? $membership->role;

            return (bool) $membership->is_active && $role === 'customer';
        }
        $historical = DB::table('customer_membership_history')
            ->where('membership_kind', 'project_organization')
            ->where('membership_id', (int) $membership->id)
            ->where('valid_from', '<=', $occurredAt)
            ->where('valid_to', '>', $occurredAt)
            ->orderByDesc('valid_from')
            ->first();

        return is_object($historical)
            && (bool) $historical->is_active
            && $historical->role === 'customer';
    }
}
