<?php

declare(strict_types=1);

namespace App\Services\OneCExchange;

use App\Enums\OneCExchangeDirection;
use App\Enums\OneCExchangeScope;
use App\Models\OneCExchangeRun;

final class OneCManualExchangeService
{
    public function __construct(
        private readonly OneCExchangeRunService $runs,
        private readonly OneCExchangeJournalService $journal
    ) {
    }

    public function import(int $organizationId, ?int $userId, array $payload): OneCExchangeRun
    {
        return $this->run($organizationId, $userId, OneCExchangeDirection::Import->value, $payload);
    }

    public function export(int $organizationId, ?int $userId, array $payload): OneCExchangeRun
    {
        return $this->run($organizationId, $userId, OneCExchangeDirection::Export->value, $payload);
    }

    private function run(int $organizationId, ?int $userId, string $direction, array $payload): OneCExchangeRun
    {
        $scope = (string) $payload['scope'];
        $run = $this->runs->createPending($organizationId, $userId, $direction, $scope);
        $operation = $this->journal->createOperation($organizationId, [
            'run_id' => $run->id,
            'created_by' => $userId,
            'operation_key' => "manual:{$run->id}",
            'correlation_id' => "manual-{$run->id}",
            'idempotency_key' => "manual:{$organizationId}:{$direction}:{$scope}:{$run->id}",
            'direction' => $direction,
            'scope' => $scope,
            'status' => 'pending',
            'payload' => [
                'scope' => $scope,
                'items_count' => is_array($payload['items'] ?? null) ? count($payload['items']) : 0,
                'dry_run' => (bool) ($payload['dry_run'] ?? false),
            ],
            'summary' => [
                'manual' => true,
                'source_is_actual' => true,
            ],
        ]);

        if (!OneCExchangeScope::tryFrom($scope)) {
            $safeError = trans_message('one_c_exchange.unsupported_scope');
            $this->journal->recordAttempt($operation, [
                'status' => 'failed',
                'failure_type' => 'business_validation',
                'safe_error_code' => 'unsupported_scope',
                'safe_error_message' => $safeError,
                'retryable' => false,
            ]);

            return $this->runs->fail($run, [
                ['message' => $safeError],
            ]);
        }

        $items = $payload['items'] ?? [];
        $totalCount = is_array($items) ? count($items) : 0;

        $summary = [
            'total_count' => $totalCount,
            'created_count' => $direction === OneCExchangeDirection::Import->value ? $totalCount : 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'error_count' => 0,
            'dry_run' => (bool) ($payload['dry_run'] ?? false),
            'message' => $direction === OneCExchangeDirection::Import->value
                ? trans_message('one_c_exchange.manual_import_accepted')
                : trans_message('one_c_exchange.manual_export_prepared'),
        ];

        $this->journal->recordAttempt($operation, [
            'status' => 'completed',
            'request' => [
                'scope' => $scope,
                'items_count' => $totalCount,
                'dry_run' => (bool) ($payload['dry_run'] ?? false),
            ],
            'response' => $summary,
            'retryable' => false,
        ]);

        return $this->runs->complete($run, $summary);
    }
}
