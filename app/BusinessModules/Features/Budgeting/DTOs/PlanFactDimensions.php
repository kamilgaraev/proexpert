<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class PlanFactDimensions
{
    public function __construct(
        public array $articles,
        public array $responsibilityCenters,
        public array $projects,
        public array $counterparties,
    ) {
    }

    public function article(?int $id): ?array
    {
        return $id === null ? null : ($this->articles[$id] ?? null);
    }

    public function responsibilityCenter(?int $id): ?array
    {
        return $id === null ? null : ($this->responsibilityCenters[$id] ?? null);
    }

    public function project(?int $id): ?array
    {
        return $id === null ? null : ($this->projects[$id] ?? null);
    }

    public function counterparty(?int $id): ?array
    {
        return $id === null ? null : ($this->counterparties[$id] ?? null);
    }

    public function flowDirection(?int $budgetArticleId): ?string
    {
        $article = $this->article($budgetArticleId);

        return is_array($article) && is_string($article['flow_direction'] ?? null)
            ? $article['flow_direction']
            : null;
    }
}
