<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

interface GeometryReviewDataSource
{
    /** @return array{content_version: string, input_version: string, model: array<string, mixed>}|null */
    public function latestModel(int $organizationId, int $projectId, int $sessionId): ?array;

    /** @return array{total: int, rows: list<object>} */
    public function sourcePage(int $organizationId, int $projectId, int $sessionId, int $page, int $perPage): array;
}
