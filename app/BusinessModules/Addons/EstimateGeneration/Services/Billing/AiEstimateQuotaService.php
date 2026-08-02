<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Billing;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureData;
use App\Exceptions\Billing\CommercialQuotaExceededException;
use App\Models\Organization;
use App\Services\Billing\CommercialQuotaService;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class AiEstimateQuotaService
{
    public const SNAPSHOT_QUERY_BUDGET = 3;

    private const TABLE = 'estimate_generation_ai_estimate_quota_reservations';

    private const CONFIRMED = 'confirmed';

    private const RELEASED = 'released';

    public function __construct(
        private Connection $database,
        private CommercialQuotaService $commercialQuota,
    ) {}

    public function reserve(EstimateGenerationSession $session): void
    {
        $organizationId = (int) $session->organization_id;
        $sessionId = (int) $session->getKey();

        if ($organizationId < 1 || $sessionId < 1) {
            throw new \InvalidArgumentException('Estimate generation session scope is invalid.');
        }

        $this->database->transaction(function () use ($organizationId, $sessionId): void {
            $lockedSession = EstimateGenerationSession::query()
                ->whereKey($sessionId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->reserveLocked($lockedSession);
        }, 3);
    }

    /** @return array{limit: int|null, used: int, available: int|null, reservation_status: 'confirmed'|'released'|null} */
    public function snapshot(EstimateGenerationSession $session): array
    {
        $sessionId = (int) $session->getKey();
        $snapshots = $this->snapshots([$session]);

        return $snapshots[$sessionId] ?? $this->emptySnapshot();
    }

    /**
     * @param  iterable<EstimateGenerationSession>  $sessions
     * @return array<int, array{limit: int|null, used: int, available: int|null, reservation_status: 'confirmed'|'released'|null}>
     */
    public function snapshots(iterable $sessions): array
    {
        $validSessions = [];
        $organizationIds = [];

        foreach ($sessions as $session) {
            $organizationId = (int) $session->organization_id;
            $sessionId = (int) $session->getKey();
            if (! $session->exists || $organizationId < 1 || $sessionId < 1) {
                continue;
            }

            $validSessions[$sessionId] = $organizationId;
            $organizationIds[$organizationId] = true;
        }

        if ($validSessions === []) {
            return [];
        }

        $organizationIds = array_map('intval', array_keys($organizationIds));
        $limits = $this->commercialQuota->getEffectiveAiEstimateMonthlyLimits($organizationIds);
        $reservationSummaries = $this->currentMonthReservationSummaries(
            $organizationIds,
            array_keys($validSessions),
        );
        $snapshots = [];

        foreach ($validSessions as $sessionId => $organizationId) {
            $limit = $limits[$organizationId] ?? null;
            $summary = $reservationSummaries[$organizationId] ?? ['used' => 0, 'statuses' => []];
            $used = max(0, (int) $summary['used']);
            $status = $summary['statuses'][$sessionId] ?? null;

            $snapshots[$sessionId] = [
                'limit' => $limit,
                'used' => $used,
                'available' => $limit === null ? null : max(0, $limit - $used),
                'reservation_status' => in_array($status, [self::CONFIRMED, self::RELEASED], true) ? $status : null,
            ];
        }

        return $snapshots;
    }

    /** @param iterable<EstimateGenerationSession> $sessions */
    public function attachSnapshots(iterable $sessions): void
    {
        $models = [];
        foreach ($sessions as $session) {
            $models[] = $session;
        }

        $snapshots = $this->snapshots($models);
        foreach ($models as $session) {
            $sessionId = (int) $session->getKey();
            if (isset($snapshots[$sessionId])) {
                $session->setAttribute('ai_estimate_quota_snapshot', $snapshots[$sessionId]);
            }
        }
    }

    /** @param Closure(EstimateGenerationSession): EstimateGenerationSession $transition */
    public function startGeneration(EstimateGenerationSession $session, Closure $transition): EstimateGenerationSession
    {
        $organizationId = (int) $session->organization_id;
        $sessionId = (int) $session->getKey();

        if ($organizationId < 1 || $sessionId < 1) {
            throw new \InvalidArgumentException('Estimate generation session scope is invalid.');
        }

        return $this->database->transaction(function () use ($organizationId, $sessionId, $transition): EstimateGenerationSession {
            $lockedSession = EstimateGenerationSession::query()
                ->whereKey($sessionId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->reserveLocked($lockedSession);

            return $transition($lockedSession);
        }, 3);
    }

    public function releaseForTerminalTechnicalFailure(
        EstimateGenerationSession $session,
        FailureData $failure,
        ?int $failedFromStateVersion = null,
    ): void
    {
        if ($failure->category !== FailureCategory::Terminal
            || $session->status !== EstimateGenerationStatus::Failed
            || $session->resume_status !== EstimateGenerationStatus::Generating
            || $failure->context->organizationId !== (int) $session->organization_id
            || $failure->context->sessionId !== (int) $session->getKey()
            || ($failedFromStateVersion !== null && (
                $failure->context->expectedSessionStateVersion !== $failedFromStateVersion
                || (int) $session->state_version !== $failedFromStateVersion + 1
            ))) {
            return;
        }

        $reservation = $this->database->table(self::TABLE)
            ->where('organization_id', $session->organization_id)
            ->where('session_id', $session->getKey())
            ->lockForUpdate()
            ->first();

        if ($reservation === null || $reservation->status === self::RELEASED) {
            return;
        }

        if ($reservation->status !== self::CONFIRMED) {
            throw new \RuntimeException('estimate_generation.ai_estimate_quota_reservation_invalid');
        }

        $released = $this->database->table(self::TABLE)
            ->where('organization_id', $session->organization_id)
            ->where('session_id', $session->getKey())
            ->where('status', self::CONFIRMED)
            ->update([
                'status' => self::RELEASED,
                'released_at' => now(),
            ]);

        if ($released !== 1) {
            throw new \RuntimeException('estimate_generation.ai_estimate_quota_release_failed');
        }
    }

    private function reserveLocked(EstimateGenerationSession $session): void
    {
        $organizationId = (int) $session->organization_id;
        $sessionId = (int) $session->getKey();
        $organization = Organization::query()
            ->whereKey($organizationId)
            ->lockForUpdate()
            ->first();

        if (! $organization instanceof Organization) {
            throw (new ModelNotFoundException)->setModel(Organization::class, [$organizationId]);
        }

        $existing = $this->database->table(self::TABLE)
            ->where('organization_id', $organizationId)
            ->where('session_id', $sessionId)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if ($existing->status === self::CONFIRMED) {
                return;
            }

            if ($existing->status === self::RELEASED) {
                $limit = $this->limit($organization);
                $used = $this->confirmedReservationsForCurrentMonth($organizationId);

                if ($limit !== null && $used + 1 > $limit) {
                    throw new CommercialQuotaExceededException('ai_estimates_month', $used, $limit, 1);
                }

                $reconfirmed = $this->database->table(self::TABLE)
                    ->where('organization_id', $organizationId)
                    ->where('session_id', $sessionId)
                    ->where('status', self::RELEASED)
                    ->update([
                        'status' => self::CONFIRMED,
                        'monthly_period' => now()->startOfMonth()->toDateString(),
                        'confirmed_at' => now(),
                        'released_at' => null,
                    ]);

                if ($reconfirmed !== 1) {
                    throw new \RuntimeException('estimate_generation.ai_estimate_quota_reconfirmation_failed');
                }

                return;
            }

            throw new \RuntimeException('estimate_generation.ai_estimate_quota_reservation_invalid');
        }

        $limit = $this->limit($organization);
        $used = $this->confirmedReservationsForCurrentMonth($organizationId);

        if ($limit !== null && $used + 1 > $limit) {
            throw new CommercialQuotaExceededException('ai_estimates_month', $used, $limit, 1);
        }

        $this->database->table(self::TABLE)->insert([
            'organization_id' => $organizationId,
            'session_id' => $sessionId,
            'monthly_period' => now()->startOfMonth()->toDateString(),
            'status' => self::CONFIRMED,
            'confirmed_at' => now(),
            'released_at' => null,
        ]);
    }

    private function limit(Organization $organization): ?int
    {
        return $this->commercialQuota->getEffectiveAiEstimateMonthlyLimits([
            (int) $organization->getKey(),
        ])[(int) $organization->getKey()] ?? null;
    }

    private function confirmedReservationsForCurrentMonth(int $organizationId): int
    {
        return $this->database->table(self::TABLE)
            ->where('organization_id', $organizationId)
            ->where('monthly_period', now()->startOfMonth()->toDateString())
            ->where('status', self::CONFIRMED)
            ->count();
    }

    /**
     * @param  list<int>  $organizationIds
     * @param  list<int>  $sessionIds
     * @return array<int, array{used: int, statuses: array<int, string>}>
     */
    private function currentMonthReservationSummaries(array $organizationIds, array $sessionIds): array
    {
        $query = $this->database->table(self::TABLE)
            ->whereIn('organization_id', $organizationIds)
            ->where('monthly_period', now()->startOfMonth()->toDateString())
            ->select('organization_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS used', [self::CONFIRMED]);

        foreach ($sessionIds as $sessionId) {
            $query->selectRaw(
                'MAX(CASE WHEN session_id = ? THEN status ELSE NULL END) AS session_'.$sessionId,
                [$sessionId],
            );
        }

        $summaries = [];
        foreach ($query->groupBy('organization_id')->get() as $row) {
            $organizationId = (int) $row->organization_id;
            $statuses = [];
            foreach ($sessionIds as $sessionId) {
                $status = $row->{'session_'.$sessionId} ?? null;
                if (is_string($status)) {
                    $statuses[$sessionId] = $status;
                }
            }

            $summaries[$organizationId] = [
                'used' => max(0, (int) $row->used),
                'statuses' => $statuses,
            ];
        }

        return $summaries;
    }

    /** @return array{limit: null, used: 0, available: null, reservation_status: null} */
    private function emptySnapshot(): array
    {
        return [
            'limit' => null,
            'used' => 0,
            'available' => null,
            'reservation_status' => null,
        ];
    }
}
