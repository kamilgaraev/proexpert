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
            ->where(static function ($builder): void {
                $builder
                    ->where('role_new', 'customer')
                    ->orWhere(static function ($fallback): void {
                        $fallback->whereNull('role_new')->where('role', 'customer');
                    });
            })
            ->whereNotNull('accepted_at')
            ->where('accepted_at', '<=', $occurredAt)
            ->get()
            ->filter(fn (object $participant): bool => $this->membershipValidAt($participant, $occurredAt))
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
            ->filter(fn (object $membership): bool => $this->membershipValidAt($membership, $occurredAt))
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

    private function membershipValidAt(object $membership, CarbonImmutable $occurredAt): bool
    {
        $updatedAt = CarbonImmutable::parse((string) $membership->updated_at);
        if ($updatedAt <= $occurredAt) {
            return (bool) $membership->is_active;
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
}
