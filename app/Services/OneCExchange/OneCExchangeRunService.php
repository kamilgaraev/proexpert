<?php

declare(strict_types=1);

namespace App\Services\OneCExchange;

use App\Enums\OneCExchangeStatus;
use App\Enums\OneCExchangeScope;
use App\Models\OneCExchangeRun;
use App\Models\OneCExchangeToken;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class OneCExchangeRunService
{
    public function createPending(int $organizationId, ?int $userId, string $direction, string $scope): OneCExchangeRun
    {
        return OneCExchangeRun::create([
            'organization_id' => $organizationId,
            'created_by' => $userId,
            'direction' => $direction,
            'scope' => $scope,
            'status' => OneCExchangeStatus::Pending->value,
            'started_at' => now(),
        ]);
    }

    public function complete(OneCExchangeRun $run, array $summary): OneCExchangeRun
    {
        $run->update([
            'status' => OneCExchangeStatus::Completed->value,
            'total_count' => (int) ($summary['total_count'] ?? 0),
            'created_count' => (int) ($summary['created_count'] ?? 0),
            'updated_count' => (int) ($summary['updated_count'] ?? 0),
            'skipped_count' => (int) ($summary['skipped_count'] ?? 0),
            'error_count' => (int) ($summary['error_count'] ?? 0),
            'summary' => $summary,
            'finished_at' => now(),
        ]);

        return $run->refresh();
    }

    public function fail(OneCExchangeRun $run, array $errors): OneCExchangeRun
    {
        $run->update([
            'status' => OneCExchangeStatus::Failed->value,
            'error_count' => count($errors),
            'errors' => $errors,
            'finished_at' => now(),
        ]);

        return $run->refresh();
    }

    public function status(int $organizationId): array
    {
        return [
            'configured' => OneCExchangeToken::query()
                ->where('organization_id', $organizationId)
                ->whereNull('revoked_at')
                ->exists(),
            'tokens_count' => OneCExchangeToken::query()
                ->where('organization_id', $organizationId)
                ->count(),
            'active_tokens_count' => OneCExchangeToken::query()
                ->where('organization_id', $organizationId)
                ->whereNull('revoked_at')
                ->count(),
            'last_run' => OneCExchangeRun::query()
                ->where('organization_id', $organizationId)
                ->latest()
                ->first(),
            'available_scopes' => array_map(
                static fn (OneCExchangeScope $scope): string => $scope->value,
                OneCExchangeScope::cases()
            ),
            'manual_only' => true,
        ];
    }

    public function history(int $organizationId, int $perPage = 20): LengthAwarePaginator
    {
        return OneCExchangeRun::query()
            ->where('organization_id', $organizationId)
            ->latest()
            ->paginate($perPage);
    }
}
