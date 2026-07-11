<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

use InvalidArgumentException;

final readonly class EvidenceData
{
    public array $locator;

    public array $value;

    public float $confidence;

    public string $sourceRef;

    public string $sourceVersion;

    public string $producerName;

    public string $producerVersion;

    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public EvidenceType $type,
        public EvidenceSourceType $sourceType,
        string $sourceRef,
        string $sourceVersion,
        array $locator,
        array $value,
        float $confidence,
        string $producerName,
        string $producerVersion,
    ) {
        if ($organizationId < 1 || $projectId < 1 || $sessionId < 1) {
            throw new InvalidArgumentException('Evidence scope identifiers must be positive.');
        }
        $this->sourceRef = (new EvidenceSourceReference($sourceType, $sourceRef))->value;
        $this->sourceVersion = (new EvidenceVersion($sourceVersion))->value;
        $this->producerName = EvidenceProducer::tryFrom($producerName)?->value
            ?? throw new InvalidArgumentException('Evidence producer is invalid.');
        $this->producerVersion = (new EvidenceVersion($producerVersion))->value;
        if (! is_finite($confidence) || $confidence < 0 || $confidence > 1) {
            throw new InvalidArgumentException('Evidence confidence must be between zero and one.');
        }

        $this->locator = EvidenceSchema::locator($type, $locator);
        $this->value = EvidenceSchema::value($type, $value);
        $this->confidence = round($confidence, 6);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'session_id' => $this->sessionId,
            'type' => $this->type->value,
            'source_type' => $this->sourceType->value,
            'source_ref' => $this->sourceRef,
            'source_version' => $this->sourceVersion,
            'locator' => $this->locator,
            'value' => $this->value,
            'confidence' => number_format($this->confidence, 6, '.', ''),
            'producer_name' => $this->producerName,
            'producer_version' => $this->producerVersion,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
