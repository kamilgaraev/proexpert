<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

use InvalidArgumentException;

final readonly class EvidenceData
{
    public array $locator;

    public array $value;

    public float $confidence;

    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public EvidenceType $type,
        public EvidenceSourceType $sourceType,
        public string $sourceRef,
        public string $sourceVersion,
        array $locator,
        array $value,
        float $confidence,
        public string $producerName,
        public string $producerVersion,
    ) {
        if ($organizationId < 1 || $projectId < 1 || $sessionId < 1) {
            throw new InvalidArgumentException('Evidence scope identifiers must be positive.');
        }
        foreach ([[$sourceRef, 160], [$sourceVersion, 80], [$producerName, 80], [$producerVersion, 80]] as [$text, $limit]) {
            if ($text === '' || strlen($text) > $limit || ! mb_check_encoding($text, 'UTF-8')) {
                throw new InvalidArgumentException('Evidence identity field is invalid.');
            }
        }
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
