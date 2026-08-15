<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use InvalidArgumentException;

final readonly class DocumentUnitOutput
{
    /** @param array<string, mixed> $normalizedPayload @param array<string, array<string, mixed>> $qualitySignals */
    public function __construct(
        public string $version,
        public string $text,
        public ?float $confidence = null,
        public array $normalizedPayload = [],
        public ?int $width = null,
        public ?int $height = null,
        public ?int $rotation = null,
        public ?DocumentUnitType $unitType = null,
        public ?int $unitIndex = null,
        public ?string $sourceVersion = null,
        public array $qualitySignals = [],
        public ?DocumentUnitPublication $publication = null,
    ) {
        if ($version === '' || strlen($version) > 80) {
            throw new InvalidArgumentException('Unit output version must contain at most 80 characters.');
        }
    }

    /** @return array<string, mixed> */
    public function persistedNormalizedPayload(): array
    {
        return $this->qualitySignals === []
            ? $this->normalizedPayload
            : [...$this->normalizedPayload, 'quality_signals' => $this->qualitySignals];
    }

    public function semanticState(): string
    {
        $arbitration = is_array($this->normalizedPayload['document_arbitration'] ?? null)
            ? $this->normalizedPayload['document_arbitration']
            : [];
        $geometry = is_array($this->normalizedPayload['geometry_expert'] ?? null)
            ? $this->normalizedPayload['geometry_expert']
            : [];
        $decisions = is_array($arbitration['decisions'] ?? null) ? $arbitration['decisions'] : [];

        $observerQuarantine = false;
        $observers = is_array($this->normalizedPayload['independent_observations'] ?? null)
            ? $this->normalizedPayload['independent_observations']
            : [];
        foreach ($observers as $observer) {
            $items = is_array($observer) && is_array($observer['observation']['quarantined_items'] ?? null)
                ? $observer['observation']['quarantined_items']
                : [];
            if ($items !== []) {
                $observerQuarantine = true;
                break;
            }
        }
        $visionQuarantine = is_array($this->normalizedPayload['vision_analysis']['quarantined_items'] ?? null)
            ? $this->normalizedPayload['vision_analysis']['quarantined_items']
            : [];
        $arbitrationQuarantine = is_array($arbitration['quarantined_intents'] ?? null)
            ? $arbitration['quarantined_intents']
            : [];
        $geometryQuarantine = is_array($geometry['quarantined_intents'] ?? null)
            ? $geometry['quarantined_intents']
            : [];
        if (($arbitration['result_state'] ?? null) === 'partial'
            || $observerQuarantine
            || $visionQuarantine !== []
            || $arbitrationQuarantine !== []
            || $geometryQuarantine !== []
            || $this->hasDecisionStatus($decisions, 'unresolved')
            || $this->hasDecisionStatus($decisions, 'candidate')) {
            return 'partial';
        }

        return 'ready';
    }

    /** @param array<mixed> $decisions */
    private function hasDecisionStatus(array $decisions, string $status): bool
    {
        foreach ($decisions as $decision) {
            if (is_array($decision) && ($decision['status'] ?? null) === $status) {
                return true;
            }
        }

        return false;
    }

    public function matches(DocumentUnitExecutionContext $context): bool
    {
        return ($this->unitType === null || $this->unitType === $context->type)
            && ($this->unitIndex === null || $this->unitIndex === $context->index)
            && ($this->sourceVersion === null || $this->sourceVersion === $context->sourceVersion);
    }
}
