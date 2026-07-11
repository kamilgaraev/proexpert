<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

use DateTimeImmutable;

final readonly class EvidenceNode
{
    public int $organizationId;

    public int $projectId;

    public int $sessionId;

    public EvidenceType $type;

    public EvidenceSourceType $sourceType;

    public string $sourceRef;

    public string $sourceVersion;

    public array $locator;

    public array $value;

    public float $confidence;

    public string $producerName;

    public string $producerVersion;

    public function __construct(
        public int $id,
        public EvidenceData $data,
        public string $fingerprint,
        public ?DateTimeImmutable $invalidatedAt = null,
        public ?string $invalidationReason = null,
        public int $invalidationVersion = 0,
    ) {
        $this->organizationId = $data->organizationId;
        $this->projectId = $data->projectId;
        $this->sessionId = $data->sessionId;
        $this->type = $data->type;
        $this->sourceType = $data->sourceType;
        $this->sourceRef = $data->sourceRef;
        $this->sourceVersion = $data->sourceVersion;
        $this->locator = $data->locator;
        $this->value = $data->value;
        $this->confidence = $data->confidence;
        $this->producerName = $data->producerName;
        $this->producerVersion = $data->producerVersion;
    }

    public function invalidate(DateTimeImmutable $at, string $reason): self
    {
        return $this->invalidatedAt === null
            ? new self($this->id, $this->data, $this->fingerprint, $at, $reason, $this->invalidationVersion + 1)
            : $this;
    }
}
