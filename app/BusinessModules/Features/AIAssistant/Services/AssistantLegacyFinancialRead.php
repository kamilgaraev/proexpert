<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class AssistantLegacyFinancialRead
{
    public static function money(mixed $value): ?string
    {
        return $value === null ? null : (string) BigDecimal::of((string) $value)->toScale(2, RoundingMode::HALF_UP);
    }

    public static function subtract(?string $left, ?string $right): ?string
    {
        return $left === null || $right === null ? null : self::money(BigDecimal::of($left)->minus($right));
    }

    public static function sum(iterable $values): ?string
    {
        $total = BigDecimal::zero();
        foreach ($values as $value) {
            if ($value === null) {
                return null;
            }
            $total = $total->plus((string) $value);
        }

        return self::money($total) ?? '0.00';
    }

    public static function percentage(?string $amount, ?string $basis): ?string
    {
        if ($amount === null || $basis === null || BigDecimal::of($basis)->isLessThanOrEqualTo(0)) {
            return null;
        }

        return (string) BigDecimal::of($amount)->multipliedBy(100)->dividedBy($basis, 2, RoundingMode::HALF_UP);
    }

    public function allowed(User $actor, int $organizationId, string $permission): bool
    {
        return app(AuthorizationService::class)->canCurrent($actor, $permission, ['organization_id' => $organizationId]);
    }

    public function projectBudget(User $actor, int $organizationId, mixed $amount): ?string
    {
        return $this->allowed($actor, $organizationId, 'finance.view_project_budget') ? self::money($amount) : null;
    }

    public function projectSpent(User $actor, int $organizationId, int $projectId): array
    {
        $scope = app(AssistantDataAccessPolicy::class)->entityQuery($actor, $organizationId, 'completed_work');
        if ($scope === null || ! $this->allowed($actor, $organizationId, 'finance.view')) {
            return ['spent' => null, 'works_count' => null];
        }
        $base = DB::table('completed_works')->where('organization_id', $organizationId)
            ->where('project_id', $projectId)->where('status', 'confirmed')->whereNull('deleted_at');
        $query = (clone $base)->whereIn('id', $scope->select('completed_works.id'));
        if ((clone $query)->count() !== (clone $base)->count()) {
            return ['spent' => null, 'works_count' => null];
        }
        if ((clone $query)->whereNull('total_amount')->exists()) {
            return ['spent' => null, 'works_count' => null];
        }
        $totals = $query->selectRaw('COALESCE(SUM(total_amount), 0) AS spent, COUNT(*) AS works_count')->first();

        return ['spent' => self::money($totals->spent), 'works_count' => (int) $totals->works_count];
    }
}
