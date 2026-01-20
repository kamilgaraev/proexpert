<?php

namespace App\BusinessModules\Addons\AIEstimates\DTOs;

use Illuminate\Http\UploadedFile;

class AIEstimateRequestDTO
{
    /**
     * @param int $projectId
     * @param int $organizationId
     * @param int $userId
     * @param string $description
     * @param float|null $area
     * @param string|null $buildingType
     * @param string|null $region
     * @param UploadedFile[]|null $files
     */
    public function __construct(
        public readonly int $projectId,
        public readonly int $organizationId,
        public readonly int $userId,
        public readonly string $description,
        public readonly ?float $area = null,
        public readonly ?string $buildingType = null,
        public readonly ?string $region = null,
        public readonly ?array $files = null,
    ) {}

    public static function fromRequest(array $data, int $projectId, int $organizationId, int $userId): self
    {
        return new self(
            projectId: $projectId,
            organizationId: $organizationId,
            userId: $userId,
            description: $data['description'],
            area: $data['area'] ?? null,
            buildingType: $data['building_type'] ?? null,
            region: $data['region'] ?? null,
            files: $data['files'] ?? null,
        );
    }

    public function hasFiles(): bool
    {
        return !empty($this->files);
    }

    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'organization_id' => $this->organizationId,
            'user_id' => $this->userId,
            'description' => $this->description,
            'area' => $this->area,
            'building_type' => $this->buildingType,
            'region' => $this->region,
            'has_files' => $this->hasFiles(),
        ];
    }
}
