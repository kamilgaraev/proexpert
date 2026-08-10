<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Conflict;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use Closure;

final readonly class TargetedConflictResolver
{
    private Closure $translator;

    public function __construct(?Closure $translator = null)
    {
        $this->translator = $translator ?? static fn (string $key, array $replace): string => trans_message($key, $replace);
    }

    public function question(Conflict $conflict, array $evidenceById): array
    {
        $options = array_map(fn (Fact $fact): array => [
            'fact_id' => $fact->id,
            'label' => ($this->translator)('estimate_generation.project_model.conflict_option', [
                'value' => $this->value($fact),
                'source' => $this->source($fact, $evidenceById),
            ]),
            'evidence_ids' => $fact->evidenceIds,
        ], $conflict->facts);

        return [
            'conflict_id' => $conflict->id,
            'text' => ($this->translator)('estimate_generation.project_model.conflict_question', [
                'fact' => $this->factName($conflict->facts[0]->type),
            ]),
            'options' => $options,
        ];
    }

    public function insufficientEvidence(): string
    {
        return ($this->translator)('estimate_generation.project_model.insufficient_evidence', []);
    }

    public function budgetExceeded(): string
    {
        return ($this->translator)('estimate_generation.project_model.operation_limit', []);
    }

    public function providerUnavailable(): string
    {
        return ($this->translator)('estimate_generation.project_model.arbitration_unavailable', []);
    }

    public function unresolvedQuestion(string $id, array $facts = [], array $evidenceById = []): array
    {
        $options = array_map(fn (Fact $fact): array => [
            'value' => 'select:'.$fact->id,
            'fact_id' => $fact->id,
            'label' => ($this->translator)('estimate_generation.project_model.conflict_option', [
                'value' => $this->value($fact),
                'source' => $this->source($fact, $evidenceById),
            ]),
            'evidence_ids' => $fact->evidenceIds,
        ], $facts);
        $options[] = [
            'value' => 'leave_unresolved',
            'label' => ($this->translator)('estimate_generation.project_model.leave_unresolved', []),
            'evidence_ids' => [],
        ];
        $options[] = [
            'value' => 'other',
            'label' => ($this->translator)('estimate_generation.project_model.other_source', []),
            'evidence_ids' => [],
        ];

        return [
            'conflict_id' => $id,
            'text' => ($this->translator)('estimate_generation.project_model.manual_review_question', []),
            'fact_ids' => array_map(static fn (Fact $fact): string => $fact->id, $facts),
            'evidence_ids' => array_values(array_unique(array_merge(...array_map(
                static fn (Fact $fact): array => $fact->evidenceIds,
                $facts,
            )))),
            'options' => $options,
        ];
    }

    private function source(Fact $fact, array $evidenceById): string
    {
        foreach ($fact->evidenceIds as $evidenceId) {
            $evidence = $evidenceById[$evidenceId] ?? null;
            if ($evidence instanceof Evidence) {
                return ($this->translator)(
                    $evidence->page === null
                        ? 'estimate_generation.project_model.source_without_page'
                        : 'estimate_generation.project_model.source_reference',
                    array_filter([
                        'document' => $evidence->sourceArtifactId,
                        'page' => $evidence->page,
                    ], static fn (mixed $value): bool => $value !== null),
                );
            }
        }

        return $fact->origin;
    }

    private function value(Fact $fact): string
    {
        $value = is_scalar($fact->value)
            ? (string) $fact->value
            : json_encode($fact->value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $value.($fact->unit === null ? '' : ' '.$fact->unit);
    }

    private function factName(string $type): string
    {
        $name = match ($type) {
            'area' => 'area',
            'dimension' => 'dimension',
            'material', 'material_name' => 'material',
            'equipment', 'equipment_model' => 'equipment',
            'quantity' => 'quantity',
            'room_number' => 'room_number',
            default => 'parameter',
        };

        return ($this->translator)('estimate_generation.project_model.fact_type.'.$name, []);
    }
}
