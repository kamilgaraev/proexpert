<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationItem;
use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationDecision;
use App\BusinessModules\Core\AccessRecertification\Services\AccessRecertificationService;
use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventFilters;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditQueryService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\RateCoefficient;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\OneCExchange\OneCExchangeRunService;
use App\Services\OneCExchange\OneCExchangeJournalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class MobileSystemService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AccessController $moduleAccess,
        private readonly OneCExchangeRunService $oneCExchangeRuns,
        private readonly OneCExchangeJournalService $oneCExchangeJournal,
        private readonly AccessRecertificationService $accessRecertification,
        private readonly ImmutableAuditQueryService $auditQuery,
    ) {}

    public function oneCStatus(User $actor, int $organizationId): array
    {
        $this->assertPermission($actor, $organizationId, 'one_c_exchange.view', 'one-c-basic-exchange');

        return $this->oneCExchangeRuns->status($organizationId);
    }

    public function oneCHistory(User $actor, int $organizationId, int $perPage): LengthAwarePaginator
    {
        $this->assertPermission($actor, $organizationId, 'one_c_exchange.history.view', 'one-c-basic-exchange');

        return $this->oneCExchangeRuns->history($organizationId, $this->boundedPageSize($perPage));
    }

    public function retryOneCOperation(User $actor, int $organizationId, int $operationId): array
    {
        $this->assertPermission($actor, $organizationId, 'one_c_exchange.retry', 'one-c-basic-exchange');

        return $this->oneCExchangeJournal->retry($organizationId, $operationId, (int) $actor->id);
    }

    public function campaigns(User $actor, int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        $this->assertPermission($actor, $organizationId, 'access_recertification.campaigns.view', 'access_recertification');

        return $this->accessRecertification->campaigns(
            $organizationId,
            $filters,
            $this->boundedPageSize($perPage),
        );
    }

    public function reviewQueue(User $actor, int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        $this->assertPermission($actor, $organizationId, 'access_recertification.reviews.view', 'access_recertification');

        return $this->accessRecertification->reviewQueue(
            $organizationId,
            $actor,
            $filters,
            $this->boundedPageSize($perPage),
        );
    }

    public function decide(
        User $actor,
        int $organizationId,
        AccessRecertificationItem $item,
        array $data,
    ): AccessRecertificationDecision {
        $this->assertPermission($actor, $organizationId, 'access_recertification.reviews.decide', 'access_recertification');

        return $this->accessRecertification->decide($item, $organizationId, $actor, $data);
    }

    public function currentRateCoefficients(
        User $actor,
        int $organizationId,
        string $appliesTo,
        ?string $scope,
        int $perPage,
    ): LengthAwarePaginator {
        $this->assertPermission($actor, $organizationId, 'rate_coefficients.view', 'rate-management');

        return RateCoefficient::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where('applies_to', $appliesTo)
            ->when($scope !== null, fn (Builder $query) => $query->where('scope', $scope))
            ->where(fn (Builder $query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', Carbon::today()))
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', Carbon::today()))
            ->orderBy('scope')
            ->orderBy('name')
            ->paginate($this->boundedPageSize($perPage));
    }

    public function systemEvents(
        User $actor,
        int $organizationId,
        ImmutableAuditEventFilters $filters,
    ): LengthAwarePaginator {
        $this->assertPermission($actor, $organizationId, 'system-logs.system.view', 'system-logs');

        return $this->auditQuery->paginate($filters);
    }

    private function assertPermission(User $actor, int $organizationId, string $permission, string $moduleSlug): void
    {
        if ($organizationId < 1 || ! $this->authorization->can($actor, $permission, [
            'organization_id' => $organizationId,
        ]) || ! $this->moduleAccess->hasModuleAccess($organizationId, $moduleSlug)) {
            throw new AuthorizationException();
        }
    }

    private function boundedPageSize(int $perPage): int
    {
        return min(100, max(1, $perPage));
    }
}
