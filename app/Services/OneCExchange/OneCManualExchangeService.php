<?php

declare(strict_types=1);

namespace App\Services\OneCExchange;

use App\Enums\OneCExchangeDirection;
use App\Enums\OneCExchangeScope;
use App\Models\OneCExchangeRun;

final class OneCManualExchangeService
{
    public function __construct(
        private readonly OneCExchangeRunService $runs
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

        if (!OneCExchangeScope::tryFrom($scope)) {
            return $this->runs->fail($run, [
                ['message' => 'Выбранный раздел обмена пока не поддерживается.'],
            ]);
        }

        $items = $payload['items'] ?? [];
        $totalCount = is_array($items) ? count($items) : 0;

        return $this->runs->complete($run, [
            'total_count' => $totalCount,
            'created_count' => $direction === OneCExchangeDirection::Import->value ? $totalCount : 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'error_count' => 0,
            'dry_run' => (bool) ($payload['dry_run'] ?? false),
            'message' => $direction === OneCExchangeDirection::Import->value
                ? 'Данные приняты для ручного импорта.'
                : 'Данные подготовлены для выгрузки.',
        ]);
    }
}
