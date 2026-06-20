<?php

declare(strict_types=1);

namespace App\Services\Project;

final class ProjectBudgetAmountService
{
    public function applyProjectPlannedCost(array $payload, int|float|string|null $amount, string $source): array
    {
        $payload['budget_amount'] = $this->normalizeAmount($amount);

        $additionalInfo = $payload['additional_info'] ?? [];
        if (! is_array($additionalInfo)) {
            $additionalInfo = [];
        }

        $additionalInfo['budget_amount_context'] = $this->context($source);

        $payload['additional_info'] = $additionalInfo;

        return $payload;
    }

    public function allowsApprovedEstimateAutoOverwrite(): bool
    {
        return false;
    }

    public function preserveProjectPlannedCostContext(array $payload, mixed $currentAdditionalInfo, string $fallbackSource): array
    {
        $additionalInfo = $payload['additional_info'] ?? [];
        if (! is_array($additionalInfo)) {
            $additionalInfo = [];
        }

        $currentContext = is_array($currentAdditionalInfo)
            ? ($currentAdditionalInfo['budget_amount_context'] ?? null)
            : null;

        $additionalInfo['budget_amount_context'] = is_array($currentContext)
            ? $this->normalizeContext($currentContext, $fallbackSource)
            : $this->context($fallbackSource);

        $payload['additional_info'] = $additionalInfo;

        return $payload;
    }

    public function amountsDiffer(int|float|string|null $currentAmount, int|float|string|null $nextAmount): bool
    {
        $current = $this->normalizeAmount($currentAmount);
        $next = $this->normalizeAmount($nextAmount);

        if ($current === null || $next === null) {
            return $current !== $next;
        }

        return abs($current - $next) > 0.00001;
    }

    private function normalizeAmount(int|float|string|null $amount): ?float
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return (float) $amount;
    }

    private function context(string $source): array
    {
        return [
            'contour' => 'project_planned_cost',
            'source' => $source,
            'creates_budget_lines' => false,
        ];
    }

    private function normalizeContext(array $context, string $fallbackSource): array
    {
        return [
            'contour' => 'project_planned_cost',
            'source' => is_string($context['source'] ?? null) ? $context['source'] : $fallbackSource,
            'creates_budget_lines' => false,
        ];
    }
}
