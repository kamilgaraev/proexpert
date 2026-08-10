<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Billing;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureData;
use App\Exceptions\Billing\CommercialQuotaExceededException;
use App\Models\Organization;
use App\Services\Billing\CommercialQuotaService;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;

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

    public function reserveSession(string $organizationId, string $sessionId): QuotaSnapshot
    {
        [$organizationKey, $sessionKey] = $this->validatedScope($organizationId, $sessionId);

        $this->database->transaction(function () use ($organizationKey, $sessionKey): void {
            $session = $this->lockedSession($organizationKey, $sessionKey);
            $this->reserveLocked($session);
        }, 3);

        return $this->snapshotForSession($organizationKey, $sessionKey);
    }

    public function releaseTechnicalFailure(string $organizationId, string $sessionId): QuotaSnapshot
    {
        [$organizationKey, $sessionKey] = $this->validatedScope($organizationId, $sessionId);

        $this->database->transaction(function () use ($organizationKey, $sessionKey): void {
            $this->lockedSession($organizationKey, $sessionKey);

            if ($this->hasUsableDraft($sessionKey)) {
                return;
            }

            $reservation = $this->database->table(self::TABLE)
                ->where('organization_id', $organizationKey)
                ->where('session_id', $sessionKey)
                ->lockForUpdate()
                ->first();

            if ($reservation === null || $reservation->status === self::RELEASED) {
                return;
            }

            if ($reservation->status !== self::CONFIRMED) {
                throw new \RuntimeException('estimate_generation.ai_estimate_quota_reservation_invalid');
            }

            $released = $this->database->table(self::TABLE)
                ->where('organization_id', $organizationKey)
                ->where('session_id', $sessionKey)
                ->where('status', self::CONFIRMED)
                ->update([
                    'status' => self::RELEASED,
                    'released_at' => now(),
                ]);

            if ($released !== 1) {
                throw new \RuntimeException('estimate_generation.ai_estimate_quota_release_failed');
            }
        }, 3);

        return $this->snapshotForSession($organizationKey, $sessionKey);
    }

    public function snapshot(string $organizationId): QuotaSnapshot
    {
        $organizationKey = $this->validatedId($organizationId, 'organization');

        return $this->organizationSnapshot($organizationKey, null);
    }

    public function sessionSnapshot(string $organizationId, string $sessionId): QuotaSnapshot
    {
        [$organizationKey, $sessionKey] = $this->validatedScope($organizationId, $sessionId);

        return $this->snapshotForSession($organizationKey, $sessionKey);
    }

    public function reserve(EstimateGenerationSession $session): void
    {
        $this->reserveSession((string) $session->organization_id, (string) $session->getKey());
    }

    /**
     * @param  iterable<EstimateGenerationSession>  $sessions
     * @return array<int, array{included: int, purchased: int|null, used: int, available: int|null, reservation_status: string|null}>
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
            $summary = $reservationSummaries[$organizationId] ?? ['used' => 0, 'statuses' => []];
            $snapshots[$sessionId] = $this->makeSnapshot(
                array_key_exists($organizationId, $limits) ? $limits[$organizationId] : $this->included(),
                max(0, (int) $summary['used']),
                $summary['statuses'][$sessionId] ?? null,
            )->toArray();
        }

        return $snapshots;
    }

    /** @param iterable<EstimateGenerationSession> $sessions */
    public function attachSnapshots(iterable $sessions): void
    {
        $models = is_array($sessions) ? $sessions : iterator_to_array($sessions, false);
        $snapshots = $this->snapshots($models);

        foreach ($models as $session) {
            $sessionId = (int) $session->getKey();
            if (isset($snapshots[$sessionId])) {
                $session->setAttribute('ai_estimate_quota_snapshot', $snapshots[$sessionId]);
            }
        }
    }

    /** @param Closure(EstimateGenerationSession): EstimateGenerationSession $transition */
    public function reserveSessionWithTransition(
        EstimateGenerationSession $session,
        Closure $transition,
    ): EstimateGenerationSession {
        [$organizationId, $sessionId] = $this->validatedScope(
            (string) $session->organization_id,
            (string) $session->getKey(),
        );

        return $this->database->transaction(function () use ($organizationId, $sessionId, $transition): EstimateGenerationSession {
            $lockedSession = $this->lockedSession($organizationId, $sessionId);
            $this->reserveLocked($lockedSession);

            return $transition($lockedSession);
        }, 3);
    }

    public function releaseForTerminalTechnicalFailure(
        EstimateGenerationSession $session,
        FailureData $failure,
        ?int $failedFromStateVersion = null,
    ): void {
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

        $this->releaseTechnicalFailure(
            (string) $session->organization_id,
            (string) $session->getKey(),
        );
    }

    private function reserveLocked(EstimateGenerationSession $session): void
    {
        $organizationId = (int) $session->organization_id;
        $sessionId = (int) $session->getKey();
        $organization = Organization::query()->whereKey($organizationId)->lockForUpdate()->first();

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

            if ($existing->status !== self::RELEASED) {
                throw new \RuntimeException('estimate_generation.ai_estimate_quota_reservation_invalid');
            }

            $this->assertAvailable($organizationId);
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

        $this->assertAvailable($organizationId);
        $this->database->table(self::TABLE)->insert([
            'organization_id' => $organizationId,
            'session_id' => $sessionId,
            'monthly_period' => now()->startOfMonth()->toDateString(),
            'status' => self::CONFIRMED,
            'confirmed_at' => now(),
            'released_at' => null,
        ]);
    }

    private function assertAvailable(int $organizationId): void
    {
        $limit = $this->limit($organizationId);
        $used = $this->confirmedReservationsForCurrentMonth($organizationId);

        if ($limit !== null && $used + 1 > $limit) {
            throw new CommercialQuotaExceededException('ai_estimates_month', $used, $limit, 1);
        }
    }

    private function limit(int $organizationId): ?int
    {
        $limits = $this->commercialQuota->getEffectiveAiEstimateMonthlyLimits([$organizationId]);

        return array_key_exists($organizationId, $limits) ? $limits[$organizationId] : $this->included();
    }

    private function included(): int
    {
        return max(0, (int) config('commercial_limits.ai_estimates.included_monthly', 10));
    }

    private function confirmedReservationsForCurrentMonth(int $organizationId): int
    {
        return $this->database->table(self::TABLE)
            ->where('organization_id', $organizationId)
            ->where('monthly_period', now()->startOfMonth()->toDateString())
            ->where('status', self::CONFIRMED)
            ->count();
    }

    private function snapshotForSession(int $organizationId, int $sessionId): QuotaSnapshot
    {
        $summary = $this->currentMonthReservationSummaries([$organizationId], [$sessionId])[$organizationId]
            ?? ['used' => 0, 'statuses' => []];

        return $this->makeSnapshot(
            $this->limit($organizationId),
            max(0, (int) $summary['used']),
            $summary['statuses'][$sessionId] ?? null,
        );
    }

    private function organizationSnapshot(int $organizationId, ?string $status): QuotaSnapshot
    {
        return $this->makeSnapshot(
            $this->limit($organizationId),
            $this->confirmedReservationsForCurrentMonth($organizationId),
            $status,
        );
    }

    private function makeSnapshot(?int $limit, int $used, ?string $status): QuotaSnapshot
    {
        $included = $this->included();

        return new QuotaSnapshot(
            included: $included,
            purchased: $limit === null ? null : max(0, $limit - $included),
            used: max(0, $used),
            available: $limit === null ? null : max(0, $limit - $used),
            reservationStatus: in_array($status, [self::CONFIRMED, self::RELEASED], true) ? $status : null,
        );
    }

    private function hasUsableDraft(int $sessionId): bool
    {
        return EstimateGenerationPackage::query()
            ->where('session_id', $sessionId)
            ->whereIn('status', ['ready_for_review', 'review_required', 'approved'])
            ->exists();
    }

    private function lockedSession(int $organizationId, int $sessionId): EstimateGenerationSession
    {
        return EstimateGenerationSession::query()
            ->whereKey($sessionId)
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @return array{int, int} */
    private function validatedScope(string $organizationId, string $sessionId): array
    {
        return [
            $this->validatedId($organizationId, 'organization'),
            $this->validatedId($sessionId, 'session'),
        ];
    }

    private function validatedId(string $id, string $scope): int
    {
        if (! ctype_digit($id) || (int) $id < 1) {
            throw new \InvalidArgumentException("Estimate generation {$scope} scope is invalid.");
        }

        return (int) $id;
    }

    /**
     * @param  list<int>  $organizationIds
     * @param  list<int>  $sessionIds
     * @return array<int, array{used: int, statuses: array<int, string>}>
     */
    private function currentMonthReservationSummaries(array $organizationIds, array $sessionIds): array
    {
        $currentPeriod = now()->startOfMonth()->toDateString();
        $query = $this->database->table(self::TABLE)
            ->whereIn('organization_id', $organizationIds)
            ->where(static function (Builder $query) use ($currentPeriod, $sessionIds): void {
                $query->where('monthly_period', $currentPeriod)
                    ->orWhereIn('session_id', $sessionIds);
            })
            ->select('organization_id')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN monthly_period = ? AND status = ? THEN 1 ELSE 0 END), 0) AS used',
                [$currentPeriod, self::CONFIRMED],
            );

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
}
