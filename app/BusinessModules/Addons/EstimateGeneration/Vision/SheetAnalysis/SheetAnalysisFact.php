<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\SheetAnalysis;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\ProjectiveTransformData;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;

final readonly class SheetAnalysisFact
{
    /** @param array<string, mixed> $value @param array<int, array{0: float, 1: float}>|string $sourcePolygonOrNativeRef */
    private function __construct(
        public string $entityKey,
        public string $factType,
        public array $value,
        public ?string $unit,
        public string $evidenceRef,
        public array|string $sourcePolygonOrNativeRef,
        public float $confidence,
        public string $contractVersion,
        public ?array $semanticProposal = null,
    ) {}

    /** @param array<string, mixed> $fact */
    public static function fromValidatedArray(array $fact): self
    {
        /** @var array<string, mixed> $value */
        $value = $fact['value'];
        /** @var array<int, array{0: float, 1: float}>|string $source */
        $source = $fact['sourcePolygonOrNativeRef'];

        return new self(
            $fact['entityKey'],
            $fact['factType'],
            $value,
            $fact['unit'],
            $fact['evidenceRef'],
            $source,
            (float) $fact['confidence'],
            $fact['contractVersion'],
            isset($fact['semanticProposal']) ? FactVocabulary::semanticProposal($fact['semanticProposal'], $fact['factType'], $fact['entityKey'], $value, $fact['unit']) : null,
        );
    }

    public function mapPolygonToSource(ProjectiveTransformData $transform): self
    {
        if (is_string($this->sourcePolygonOrNativeRef) && $this->semanticProposal === null) {
            return $this;
        }

        return new self(
            $this->entityKey,
            $this->factType,
            $this->value,
            $this->unit,
            $this->evidenceRef,
            is_string($this->sourcePolygonOrNativeRef) ? $this->sourcePolygonOrNativeRef : array_map($transform->toSource(...), $this->sourcePolygonOrNativeRef),
            $this->confidence,
            $this->contractVersion,
            FactVocabulary::mapSemanticSources($this->semanticProposal, static fn (array $field): array => [
                ...$field, 'sourcePolygonOrNativeRef' => is_array($field['sourcePolygonOrNativeRef'])
                    ? array_map($transform->toSource(...), $field['sourcePolygonOrNativeRef']) : $field['sourcePolygonOrNativeRef']]),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entityKey' => $this->entityKey,
            'factType' => $this->factType,
            'value' => $this->value,
            'unit' => $this->unit,
            'evidenceRef' => $this->evidenceRef,
            'sourcePolygonOrNativeRef' => $this->sourcePolygonOrNativeRef,
            'confidence' => $this->confidence,
            'contractVersion' => $this->contractVersion,
            ...($this->semanticProposal === null ? [] : ['semanticProposal' => $this->semanticProposal]),
        ];
    }
}
