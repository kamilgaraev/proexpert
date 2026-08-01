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
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class AiEstimateQuotaService
{
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
                return;
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
        }, 3);
    }

    public function releaseForTerminalTechnicalFailure(EstimateGenerationSession $session, FailureData $failure): void
    {
        if ($failure->category !== FailureCategory::Terminal
            || $session->status !== EstimateGenerationStatus::Failed
            || $session->resume_status !== EstimateGenerationStatus::Generating
            || $failure->context->organizationId !== (int) $session->organization_id
            || $failure->context->sessionId !== (int) $session->getKey()) {
            return;
        }

        $this->database->table(self::TABLE)
            ->where('organization_id', $session->organization_id)
            ->where('session_id', $session->getKey())
            ->where('status', self::CONFIRMED)
            ->update([
                'status' => self::RELEASED,
                'released_at' => now(),
            ]);
    }

    private function limit(Organization $organization): ?int
    {
        $limits = $this->commercialQuota->getEffectiveLimits($organization);
        $limit = $limits['ai_estimates_month'] ?? null;

        return $limit === null ? null : max(0, (int) $limit);
    }

    private function confirmedReservationsForCurrentMonth(int $organizationId): int
    {
        return $this->database->table(self::TABLE)
            ->where('organization_id', $organizationId)
            ->where('monthly_period', now()->startOfMonth()->toDateString())
            ->where('status', self::CONFIRMED)
            ->count();
    }
}
