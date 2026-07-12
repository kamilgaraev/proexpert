<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateData;

interface NormativeCandidateSource
{
    /** @return list<NormativeCandidateData> */
    public function find(int $organizationId, int $projectId, string $datasetVersion, string $query, int $limit, ?string $semanticIndexVersion): array;
}
