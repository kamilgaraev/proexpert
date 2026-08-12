<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\AssembleMatchedResources;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CanonicalTechnologyWorkItemPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendation;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemOption;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Throwable;

final readonly class DeterministicEstimateChangePreview implements EstimateChangeSimulation
{
    public function __construct(
        private EstimateDialogueContextSnapshotRepository $snapshots,
        private CurrentProjectDerivedQuantityService $quantities,
        private ResourceAssemblyService $resources,
        private AssembleMatchedResources $matchedResources,
        private EstimatePricingService $pricing,
        private CanonicalTechnologyWorkItemPlanner $technologyWorkItems = new CanonicalTechnologyWorkItemPlanner,
    ) {}

    public function calculate(EstimateGenerationSession $session, EstimateCommandInterpretation $interpretation): array
    {
        $exact = $this->snapshots->capture(
            (int) $session->organization_id,
            (int) $session->project_id,
            (int) $session->getKey(),
        );
        $fingerprint = $this->fingerprint($exact, $interpretation, []);
        $versionFence = $exact->versionFence();
        $decisions = $exact->decisions;
        $technologyOption = null;
        if ($interpretation->kind() === 'correct_fact') {
            $model = $this->correctedModel($exact->projectModel, $interpretation);
        } elseif ($interpretation->kind() === 'select_technology') {
            [$model, $decisions, $technologyOption] = $this->technologyModel(
                $exact->projectModel,
                $exact->technology,
                $decisions,
                $interpretation,
            );
        } else {
            $model = null;
        }
        if (! $model instanceof ProjectModelSnapshot) {
            return $this->unknown('canonical_project_model_target_missing', $fingerprint, $versionFence);
        }
        $projection = $this->quantities->simulate(
            $model,
            $exact->projectModelToken,
            $exact->technology,
            $exact->completeness,
            $decisions,
        );
        if ($projection['warnings'] !== []) {
            $blockers = array_values(array_unique(array_map(
                static fn (array $warning): string => (string) ($warning['code'] ?? 'canonical_quantity_unresolved'),
                $projection['warnings'],
            )));

            return [
                ...$this->unknown($blockers[0] ?? 'canonical_quantity_unresolved', $fingerprint, $versionFence),
                'blockers' => $blockers,
            ];
        }

        $dependencies = array_values(array_filter(array_map(
            'strval',
            is_array($interpretation->payload['dependency_keys'] ?? null)
                ? $interpretation->payload['dependency_keys']
                : [],
        )));
        $allBeforeRows = $this->rows($exact->draftPayload);
        $beforeRows = array_values(array_filter(
            $allBeforeRows,
            fn (array $row): bool => $interpretation->kind() === 'select_technology'
                ? ($row['metadata']['technology_decision_key'] ?? null) === ($interpretation->payload['decision_key']
                    ?? $interpretation->payload['after']['decision_key'] ?? null)
                : array_intersect(
                    $dependencies,
                    is_array($row['metadata']['dependency_keys'] ?? null)
                        ? $row['metadata']['dependency_keys']
                        : [],
                ) !== [],
        ));
        if ($beforeRows === []) {
            return $this->unknown('affected_rows_not_found', $fingerprint, $versionFence);
        }
        if (count($beforeRows) > 5000) {
            return $this->unknown('proposal_too_large', $fingerprint, $versionFence);
        }

        $assumptions = [];
        $risks = [];
        if ($interpretation->kind() === 'select_technology') {
            if (! $technologyOption instanceof TechnologySystemOption) {
                return $this->unknown('canonical_work_package_missing', $fingerprint, $versionFence);
            }
            $decisionKey = (string) ($interpretation->payload['decision_key']
                ?? $interpretation->payload['after']['decision_key']
                ?? '');
            $technologyPlan = $this->technologyWorkItems->simulateOption(
                $technologyOption,
                $model,
                $projection,
                $decisionKey,
            );
            if ($technologyPlan['blockers'] !== []) {
                return [
                    ...$this->unknown($technologyPlan['blockers'][0], $fingerprint, $versionFence),
                    'blockers' => $technologyPlan['blockers'],
                    'assumptions' => $technologyPlan['assumptions'],
                    'risks' => $technologyPlan['risks'],
                ];
            }
            $projection['quantities'] = [...$projection['quantities'], ...$technologyPlan['quantities']];
            $candidateRows = $technologyPlan['rows'];
            $assumptions = $technologyPlan['assumptions'];
            $risks = $technologyPlan['risks'];
        } else {
            $candidateRows = $beforeRows;
        }
        if ($candidateRows === []) {
            return $this->unknown('canonical_work_package_missing', $fingerprint, $versionFence);
        }
        foreach ($candidateRows as $index => $row) {
            $quantityKey = (string) ($row['metadata']['quantity_key'] ?? '');
            $quantity = $projection['quantities'][$quantityKey] ?? null;
            if ($quantity === null) {
                return $this->unknown('canonical_quantity_mapping_missing', $fingerprint, $versionFence);
            }
            $candidateRows[$index] = $this->invalidateDownstream([
                ...$row,
                'quantity' => $quantity->amount,
                'unit' => $quantity->unit,
                'quantity_evidence' => $quantity->toArray(),
            ]);
        }

        try {
            $regional = is_array($exact->draftPayload['regional_context'] ?? null)
                ? $exact->draftPayload['regional_context']
                : [];
            $enriched = $this->resources->enrich($candidateRows, $regional);
            $assembled = $this->matchedResources->handle([
                'local_estimates' => [['sections' => [['work_items' => $enriched]]]],
                'supplementary_materials' => $exact->draftPayload['supplementary_materials'] ?? [],
            ]);
            $afterRows = $this->pricing->price($this->rows($assembled['data'] ?? []), $regional);
        } catch (Throwable) {
            return $this->unknown('canonical_pricing_unavailable', $fingerprint, $versionFence);
        }

        $afterAllRows = $this->replaceRows($allBeforeRows, $beforeRows, $afterRows);
        $beforeTotal = $this->total($allBeforeRows);
        $afterTotal = $this->total($afterAllRows);
        if ($beforeTotal === null || $afterTotal === null) {
            return $this->unknown('canonical_price_incomplete', $fingerprint, $versionFence);
        }
        $affected = [];
        $beforeByKey = $this->rowsByKey($beforeRows);
        $afterByKey = $this->rowsByKey($afterRows);
        $keys = array_values(array_unique([...array_keys($beforeByKey), ...array_keys($afterByKey)]));
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $before = $beforeByKey[$key] ?? [];
            $after = $afterByKey[$key] ?? [];
            $dependencySource = $before !== [] ? $before : $after;
            $affected[] = [
                'stable_key' => $key,
                'kind' => 'estimate_row',
                'before' => $this->summary($before),
                'after' => $this->summary($after),
                'delta' => $this->rowDelta($before, $after),
                'dependency_keys' => array_values(array_filter(
                    is_array($dependencySource['metadata']['dependency_keys'] ?? null)
                        ? $dependencySource['metadata']['dependency_keys']
                        : [],
                    'is_string',
                )),
            ];
        }

        return [
            'state' => 'known',
            'delta' => $afterTotal->minus($beforeTotal)->toScale(4, RoundingMode::HALF_UP)->__toString(),
            'blockers' => [],
            'assumptions' => $assumptions,
            'risks' => $risks,
            'affected' => $affected,
            'fingerprint' => $this->fingerprint($exact, $interpretation, $afterAllRows),
            'version_fence' => $versionFence,
        ];
    }

    private function correctedModel(
        ProjectModelSnapshot $snapshot,
        EstimateCommandInterpretation $interpretation,
    ): ?ProjectModelSnapshot {
        $target = (string) ($interpretation->payload['target_key']
            ?? $interpretation->payload['after']['assertion_stable_key']
            ?? '');
        $value = $interpretation->payload['value']
            ?? $interpretation->payload['after']['value']['value']
            ?? $interpretation->payload['after']['value']
            ?? null;
        if (is_array($value)) {
            $value = $value['value'] ?? $value['amount'] ?? null;
        }
        if ($target === '' || (! is_string($value) && ! is_int($value))) {
            return null;
        }
        $found = false;
        $facts = array_map(static function (Fact $fact) use ($target, $value, &$found): Fact {
            if ($fact->id !== $target) {
                return $fact;
            }
            $found = true;

            return new Fact(
                id: $fact->id,
                organizationId: $fact->organizationId,
                projectId: $fact->projectId,
                sessionId: $fact->sessionId,
                sourceVersion: $fact->sourceVersion,
                entityId: $fact->entityId,
                type: $fact->type,
                value: $value,
                unit: $fact->unit,
                confidence: $fact->confidence,
                origin: $fact->origin,
                status: $fact->status,
                evidenceIds: $fact->evidenceIds,
                version: $fact->version + 1,
                supersedesFactId: $fact->supersedesFactId,
            );
        }, $snapshot->facts);

        return $found
            ? new ProjectModelSnapshot($snapshot->entities, $facts, $snapshot->evidence, $snapshot->conflicts)
            : null;
    }

    /**
     * @param  list<Decision>  $decisions
     * @return array{0:?ProjectModelSnapshot,1:list<Decision>,2:?TechnologySystemOption}
     */
    private function technologyModel(
        ProjectModelSnapshot $snapshot,
        ?array $technology,
        array $decisions,
        EstimateCommandInterpretation $interpretation,
    ): array {
        $decisionKey = (string) ($interpretation->payload['decision_key']
            ?? $interpretation->payload['after']['decision_key']
            ?? '');
        $response = (string) ($interpretation->payload['option_id']
            ?? $interpretation->payload['after']['response']
            ?? '');
        $recommendation = null;
        foreach ($technology['recommendations'] ?? [] as $candidate) {
            if ($candidate instanceof TechnologyRecommendation && $candidate->decisionKey === $decisionKey) {
                $recommendation = $candidate;
                break;
            }
        }
        if (! $recommendation instanceof TechnologyRecommendation) {
            return [null, $decisions, null];
        }
        $option = null;
        foreach ($recommendation->options as $candidate) {
            if ($candidate instanceof TechnologySystemOption
                && $candidate->system->id === $response
                && $candidate->applicabilityStatus === 'applicable') {
                $option = $candidate;
                break;
            }
        }
        $target = null;
        foreach ($snapshot->facts as $fact) {
            if ($fact->id === $recommendation->targetFactId) {
                $target = $fact;
                break;
            }
        }
        if (! $option instanceof TechnologySystemOption || ! $target instanceof Fact) {
            return [null, $decisions, null];
        }
        $hash = substr(hash('sha256', $decisionKey.'|'.$response), 0, 48);
        $selected = new Fact(
            id: 'fact:simulation:'.$hash,
            organizationId: $target->organizationId,
            projectId: $target->projectId,
            sessionId: $target->sessionId,
            sourceVersion: $target->sourceVersion,
            entityId: $target->entityId,
            type: $target->type,
            value: [
                'kind' => 'catalog_system',
                'system_id' => $option->system->id,
                'catalog_version' => $recommendation->catalogVersion,
                'catalog_hash' => $recommendation->catalogHash,
                'provenance' => $option->system->provenance,
                'decision_key' => $decisionKey,
                'planning_run_id' => (int) ($technology['run_id'] ?? 0),
            ],
            unit: null,
            confidence: 1.0,
            origin: 'user_assumption',
            status: 'confirmed',
            evidenceIds: $target->evidenceIds,
            version: $target->version + 1,
            supersedesFactId: $target->id,
        );
        $decision = new Decision(
            id: 'decision:simulation:'.$hash,
            organizationId: $target->organizationId,
            projectId: $target->projectId,
            sessionId: $target->sessionId,
            sourceVersion: $target->sourceVersion,
            targetType: 'fact',
            targetId: $target->id,
            selectedFactId: $selected->id,
            actorType: 'user',
            actorId: 'simulation',
            reason: 'Подтверждённая изолированная симуляция',
            version: $selected->version,
            evidenceIds: $target->evidenceIds,
        );
        $decisions = array_values(array_filter(
            $decisions,
            static fn (Decision $current): bool => $current->targetId !== $target->id,
        ));

        return [
            new ProjectModelSnapshot(
                $snapshot->entities,
                [...$snapshot->facts, $selected],
                $snapshot->evidence,
                $snapshot->conflicts,
            ),
            [...$decisions, $decision],
            $option,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function rows(array $draft): array
    {
        if (is_array($draft['rows'] ?? null)) {
            return array_values(array_filter($draft['rows'], 'is_array'));
        }
        $rows = [];
        foreach ($draft['local_estimates'] ?? [] as $estimate) {
            foreach (is_array($estimate['sections'] ?? null) ? $estimate['sections'] : [] as $section) {
                foreach (is_array($section['work_items'] ?? null) ? $section['work_items'] : [] as $row) {
                    if (is_array($row)) {
                        $rows[] = $row;
                    }
                }
            }
        }

        return $rows;
    }

    /** @param list<array<string,mixed>> $rows @return array<string,array<string,mixed>> */
    private function rowsByKey(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '' || isset($indexed[$key])) {
                continue;
            }
            $indexed[$key] = $row;
        }

        return $indexed;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function invalidateDownstream(array $row): array
    {
        foreach ([
            'normative_match', 'normative_candidates', 'normative_rate_code', 'normative_dataset',
            'materials', 'labor', 'machinery', 'other_resources', 'work_cost', 'materials_cost',
            'labor_cost', 'machinery_cost', 'total_cost', 'price_snapshot', 'price_source',
            'pricing_status', 'pricing_blocker', 'pricing_blocker_message', 'pricing_finalized_at',
        ] as $key) {
            unset($row[$key]);
        }
        $row['materials'] = [];
        $row['labor'] = [];
        $row['machinery'] = [];
        $row['other_resources'] = [];
        $row['validation_flags'] = array_values(array_filter(
            is_array($row['validation_flags'] ?? null) ? $row['validation_flags'] : [],
            static fn (mixed $flag): bool => is_string($flag)
                && ! str_contains($flag, 'pricing')
                && ! str_contains($flag, 'normative'),
        ));

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $allRows
     * @param  list<array<string, mixed>>  $removedRows
     * @param  list<array<string, mixed>>  $replacementRows
     * @return list<array<string, mixed>>
     */
    private function replaceRows(array $allRows, array $removedRows, array $replacementRows): array
    {
        $removed = array_fill_keys(array_keys($this->rowsByKey($removedRows)), true);
        $result = array_values(array_filter(
            $allRows,
            static fn (array $row): bool => ! isset($removed[(string) ($row['key'] ?? '')]),
        ));

        return [...$result, ...$replacementRows];
    }

    /** @param list<array<string,mixed>> $rows */
    private function total(array $rows): ?BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($rows as $row) {
            if (($row['item_type'] ?? 'priced_work') !== 'priced_work') {
                continue;
            }
            if (($row['pricing_status'] ?? null) !== 'calculated'
                || ! is_string($row['total_cost'] ?? null)
                || preg_match('/^-?\d+(?:\.\d+)?$/D', $row['total_cost']) !== 1) {
                return null;
            }
            $total = $total->plus($row['total_cost']);
        }

        return $total;
    }

    /** @return array<string,mixed> */
    private function summary(array $row): array
    {
        return array_intersect_key($row, array_flip([
            'key', 'name', 'quantity', 'unit', 'total_cost', 'pricing_status',
        ]));
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private function rowDelta(array $before, array $after): ?string
    {
        $beforeTotal = $before === [] ? '0' : ($before['total_cost'] ?? null);
        $afterTotal = $after === [] ? '0' : ($after['total_cost'] ?? null);
        if (! is_string($beforeTotal) || ! is_string($afterTotal)
            || preg_match('/^-?\d+(?:\.\d+)?$/D', $beforeTotal) !== 1
            || preg_match('/^-?\d+(?:\.\d+)?$/D', $afterTotal) !== 1) {
            return null;
        }

        return BigDecimal::of($afterTotal)
            ->minus($beforeTotal)
            ->toScale(4, RoundingMode::HALF_UP)
            ->__toString();
    }

    private function fingerprint(
        EstimateDialogueContextSnapshot $snapshot,
        EstimateCommandInterpretation $interpretation,
        array $rows,
    ): string {
        return 'sha256:'.hash('sha256', $this->canonicalJson([
            'context' => $snapshot->fingerprint(),
            'proposal' => $interpretation->payload,
            'pricing_rows' => $rows,
        ]));
    }

    private function canonicalJson(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            foreach ($item as $key => $nested) {
                $item[$key] = $normalize($nested);
            }
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }

            return $item;
        };

        return json_encode(
            $normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private function unknown(string $blocker, string $fingerprint, array $versionFence): array
    {
        return [
            'state' => 'unknown',
            'delta' => null,
            'blockers' => [$blocker],
            'affected' => [],
            'fingerprint' => $fingerprint,
            'version_fence' => $versionFence,
        ];
    }
}
