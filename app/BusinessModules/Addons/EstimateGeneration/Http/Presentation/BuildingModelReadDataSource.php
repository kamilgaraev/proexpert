<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

interface BuildingModelReadDataSource
{
    /** @return array{content_version: string, model: array<string, mixed>}|null */
    public function latestModel(int $organizationId, int $projectId, int $sessionId): ?array;

    /** @param list<int> $ids @return array<int, array<string, mixed>> */
    public function evidenceForIds(int $organizationId, int $projectId, int $sessionId, array $ids): array;

    /** @return array<string, mixed>|null */
    public function evidence(int $organizationId, int $projectId, int $sessionId, int $evidenceId): ?array;

    /** @param list<int> $documentIds @return array<int, string> */
    public function documentNames(int $organizationId, int $projectId, int $sessionId, array $documentIds): array;

    /** @return array{amount: string, evidence_id: int, confidence: float, floor_count: int, source_version?: string, fingerprint?: string, invalidation_version?: int, active?: bool}|null */
    public function totalArea(int $organizationId, int $projectId, int $sessionId): ?array;
}
