<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateCompositionProjector;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceMaterializer;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\LeaseAwarePipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineLeaseHeartbeat;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\RenewsPipelineLease;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CanonicalTechnologyWorkItemPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlanCompiler;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityResolver;
use Illuminate\Support\Facades\Log;

final readonly class PlanWorkItemsStage implements LeaseAwarePipelineStage
{
    use RenewsPipelineLease;

    public function __construct(
        private WorkPlanCompiler $compiler,
        private StageResultFactory $results,
        private AcceptedQuantityEvidenceMaterializer $acceptedEvidence,
        private RunEstimateComposer $composer,
        private EstimateComposerInputFactory $composerInputs,
        private EstimateCompositionProjector $compositionProjector = new EstimateCompositionProjector,
        private WorkItemQuantityResolver $quantityResolver = new WorkItemQuantityResolver,
        private CanonicalTechnologyWorkItemPlanner $technologyWorkItems = new CanonicalTechnologyWorkItemPlanner,
    ) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::PlanWorkItems;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        return $this->executeStage($context);
    }

    public function executeWithHeartbeat(
        PipelineContext $context,
        PipelineLeaseHeartbeat $heartbeat,
    ): PipelineStageResult {
        self::renewLease($heartbeat);
        $result = $this->executeStage($context, $heartbeat);
        self::renewLease($heartbeat);

        return $result;
    }

    private function executeStage(
        PipelineContext $context,
        ?PipelineLeaseHeartbeat $heartbeat = null,
    ): PipelineStageResult {
        $analysis = $context->priorOutputs->payload(ProcessingStage::UnderstandObject)['analysis'];
        $quantityOutput = $context->priorOutputs->payload(ProcessingStage::ExtractQuantities);
        $stage6Context = is_array($quantityOutput['stage6_generation_context'] ?? null)
            ? $quantityOutput['stage6_generation_context']
            : [];
        $hints = $quantityOutput['quantity_learning_hints'];
        if ($hints !== []) {
            $analysis['document_context']['quantity_learning_hints'] = $hints;
        }
        $coverageWarnings = is_array($quantityOutput['quantity_coverage_warnings'] ?? null)
            ? array_values(array_filter($quantityOutput['quantity_coverage_warnings'], 'is_array'))
            : [];
        if ($coverageWarnings !== []) {
            $analysis['document_context']['quantity_coverage_warnings'] = $coverageWarnings;
        }
        $quantities = [];
        foreach (($quantityOutput['building_quantities']['quantities'] ?? []) as $quantity) {
            if (! is_array($quantity)) {
                continue;
            }
            $typed = QuantityData::fromArray($quantity);
            $quantities[$typed->key] = $typed;
        }
        if ($quantities !== []) {
            $analysis['document_context']['canonical_building_quantities'] = array_map(
                static fn (QuantityData $quantity): array => $quantity->toArray(),
                array_values($quantities),
            );
        }
        $payload = $this->compiler->compile($analysis, null, true);
        $this->logProgress($context, 'baseline_compiled');
        $this->renewAfterProgress($heartbeat);
        $stageFiveItems = $this->technologyWorkItems->planPackages(
            is_array($stage6Context['work_packages'] ?? null) ? $stage6Context['work_packages'] : [],
        );
        if ($stageFiveItems !== []) {
            $payload['local_estimates'][] = [
                'key' => 'stage5-technology-packages',
                'title' => trans_message('estimate_generation.stage6.technology_packages_title'),
                'scope_type' => 'technology',
                'source_refs' => $this->sourceRefs($stageFiveItems),
                'sections' => [[
                    'key' => 'stage5-technology-packages',
                    'title' => trans_message('estimate_generation.stage6.technology_packages_section'),
                    'work_items' => $stageFiveItems,
                ]],
            ];
        }
        $this->logProgress($context, 'deterministic_candidates_planned');
        $this->renewAfterProgress($heartbeat);
        foreach ($payload['local_estimates'] as $localIndex => $localEstimate) {
            foreach ($localEstimate['sections'] as $sectionIndex => $section) {
                foreach ($section['work_items'] as $itemIndex => $item) {
                    $mapped = $this->attachCanonicalQuantity($item, $quantities, $this->quantityResolver);
                    $quantity = $mapped['quantity_evidence'] ?? null;
                    if (is_array($quantity) && ($quantity['review_blockers'] ?? []) === []) {
                        $node = $this->acceptedEvidence->materialize($context, QuantityData::fromArray($quantity), $mapped);
                        $mapped['quantity_evidence_id'] = $node->id;
                        $mapped['quantity_evidence_fingerprint'] = $node->fingerprint;
                        $mapped['quantity_evidence_source_version'] = $node->sourceVersion;
                    }
                    $payload['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $mapped;
                }
            }
        }
        $this->logProgress($context, 'quantity_evidence_materialized');
        $this->renewAfterProgress($heartbeat);
        $candidates = $this->compositionProjector->candidates($payload['local_estimates']);
        $composerInput = $this->composerInputs->capture(
            $context->organizationId,
            $context->projectId,
            $context->sessionId,
            $candidates,
            array_map(
                static fn (QuantityData $quantity): array => $quantity->toArray(),
                array_values($quantities),
            ),
            $this->missingDocuments($coverageWarnings, $stage6Context),
        );
        $intents = $this->composer->run($composerInput);
        $payload['local_estimates'] = $this->compositionProjector->attach($payload['local_estimates'], $intents);
        $payload['estimate_composition'] = [
            'schema_version' => 1,
            'snapshot_token' => $composerInput->snapshotToken,
            'input_fingerprint' => $composerInput->fingerprint(),
            'intents_count' => count($intents),
        ];
        $this->logProgress($context, 'estimate_composed');
        $this->renewAfterProgress($heartbeat);
        $regionalContext = is_array($payload['regional_context'] ?? null) ? $payload['regional_context'] : [];
        $pinStartedAt = microtime(true);
        $pinCompleted = false;
        try {
            $payload['normative_context_pin'] = $this->compiler->resolveNormativeContextPin(
                $regionalContext,
                $payload['local_estimates'],
                is_string($payload['object_profile']['object_type'] ?? null)
                    ? $payload['object_profile']['object_type']
                    : null,
                function (string $phase, array $metadata = []) use ($context, $heartbeat): void {
                    $this->renewAfterProgress($heartbeat);
                    if ($this->canLog()) {
                        Log::info('estimate_generation.normative_context_pin_progress', [
                            'session_id' => $context->sessionId,
                            'project_id' => $context->projectId,
                            'phase' => $phase,
                            ...$this->boundedProgressMetadata($metadata),
                        ]);
                    }
                },
            );
            $pinCompleted = true;
        } finally {
            if ($this->canLog()) {
                Log::info('estimate_generation.normative_context_pin_finished', [
                    'session_id' => $context->sessionId,
                    'project_id' => $context->projectId,
                    'completed' => $pinCompleted,
                    'duration_ms' => (int) round((microtime(true) - $pinStartedAt) * 1000),
                ]);
            }
        }
        $this->logProgress($context, 'normative_context_pinned');
        $this->renewAfterProgress($heartbeat);
        if ($this->canLog()) {
            Log::info('estimate_generation.quantity_evidence_plan_outcomes', [
                'session_id' => $context->sessionId,
                'project_id' => $context->projectId,
                'composition_snapshot_token' => $composerInput->snapshotToken,
                'composition_intents_count' => count($intents),
                ...$this->quantityEvidenceSummary($payload['local_estimates']),
            ]);
        }

        return $this->results->make($context, $this->stage(), $payload, [
            'local_estimates_count' => count($payload['local_estimates']),
        ]);
    }

    private function renewAfterProgress(?PipelineLeaseHeartbeat $heartbeat): void
    {
        if ($heartbeat !== null) {
            self::renewLease($heartbeat);
        }
    }

    /** @return list<array{code:string,source_fact_ids:list<string>}> */
    private function missingDocuments(array $coverageWarnings, array $stage6Context): array
    {
        $documents = [];
        foreach ($coverageWarnings as $warning) {
            if (! is_array($warning)) {
                continue;
            }
            $code = $warning['reason_code'] ?? $warning['code'] ?? $warning['quantity_key'] ?? null;
            if (! is_string($code) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/D', $code) !== 1) {
                continue;
            }
            $sourceFactIds = is_array($warning['source_fact_ids'] ?? null)
                ? array_values(array_unique(array_filter($warning['source_fact_ids'], 'is_string')))
                : [];
            $documents[$code.'|'.implode(',', $sourceFactIds)] = [
                'code' => $code,
                'source_fact_ids' => array_slice($sourceFactIds, 0, 256),
            ];
        }
        $packages = is_array($stage6Context['work_packages'] ?? null) ? $stage6Context['work_packages'] : [];
        foreach ($packages as $package) {
            if (! is_array($package) || ! in_array($package['status'] ?? null, ['unknown', 'proven_missing'], true)) {
                continue;
            }
            $code = is_string($package['id'] ?? null) ? $package['id'] : null;
            if ($code === null || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/D', $code) !== 1) {
                continue;
            }
            $sourceFactIds = is_array($package['evidence_fact_ids'] ?? null)
                ? array_values(array_unique(array_filter($package['evidence_fact_ids'], 'is_string')))
                : [];
            $documents[$code.'|'.implode(',', $sourceFactIds)] = [
                'code' => $code,
                'source_fact_ids' => array_slice($sourceFactIds, 0, 256),
            ];
        }

        return array_slice(array_values($documents), 0, 200);
    }

    private function attachCanonicalQuantity(
        array $workItem,
        array $quantities,
        ?WorkItemQuantityResolver $quantityResolver = null
    ): array {
        $quantity = ($quantityResolver ?? new WorkItemQuantityResolver)->resolve($workItem, $quantities)?->toArray();
        if (! is_array($quantity)) {
            unset($workItem['quantity'], $workItem['quantity_evidence']);
            $workItem['pricing_status'] = 'not_calculated';
            $workItem['pricing_blocker'] = 'quantity_mapping_missing';
            $workItem['validation_flags'] = array_values(array_unique([
                ...(is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : []),
                'quantity_mapping_missing',
                'requires_quantity_review',
            ]));

            return $workItem;
        }
        $workItem['quantity'] = $quantity['amount'];
        $workItem['unit'] = $quantity['unit'];
        $workItem['quantity_evidence'] = $quantity;
        $workItem['validation_flags'] = array_values(array_filter(
            is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : [],
            static fn (string $flag): bool => ! in_array($flag, [
                'document_takeoff_required',
                'quantity_mapping_missing',
                'requires_quantity_review',
            ], true)
        ));
        if ($quantity['review_blockers'] !== []) {
            $workItem['pricing_status'] = 'not_calculated';
            $workItem['pricing_blocker'] = 'quantity_review_required';
            $workItem['validation_flags'] = array_values(array_unique([
                ...(is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : []),
                ...$quantity['review_blockers'],
                'requires_quantity_review',
            ]));
        } elseif (in_array($workItem['pricing_blocker'] ?? null, [
            'quantity_mapping_missing',
            'quantity_review_required',
        ], true)) {
            $workItem['pricing_blocker'] = 'normative_required';
        }

        return $workItem;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function sourceRefs(array $items): array
    {
        $refs = [];
        foreach ($items as $item) {
            foreach (is_array($item['source_refs'] ?? null) ? $item['source_refs'] : [] as $ref) {
                if (! is_array($ref)) {
                    continue;
                }
                $refs[hash('sha256', json_encode($ref, JSON_THROW_ON_ERROR))] = $ref;
            }
        }
        ksort($refs);

        return array_values($refs);
    }

    private function quantityEvidenceSummary(array $localEstimates): array
    {
        $quantities = 0;
        $reviewFree = 0;
        $identities = 0;
        $idTypes = [];

        foreach ($localEstimates as $localEstimate) {
            foreach ($localEstimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $workItem) {
                    if (! is_array($workItem) || ! is_array($workItem['quantity_evidence'] ?? null)) {
                        continue;
                    }
                    $quantities++;
                    if (($workItem['quantity_evidence']['review_blockers'] ?? []) !== []) {
                        continue;
                    }
                    $reviewFree++;
                    if (! array_key_exists('quantity_evidence_id', $workItem)) {
                        continue;
                    }
                    $identities++;
                    $type = get_debug_type($workItem['quantity_evidence_id']);
                    $idTypes[$type] = ($idTypes[$type] ?? 0) + 1;
                }
            }
        }
        ksort($idTypes, SORT_STRING);

        return [
            'quantity_items_count' => $quantities,
            'review_free_quantity_items_count' => $reviewFree,
            'materialized_identity_items_count' => $identities,
            'identity_id_type_counts' => $idTypes,
        ];
    }

    private function canLog(): bool
    {
        $application = Log::getFacadeApplication();

        return $application !== null && $application->bound('log') && $application->bound('config');
    }

    private function logProgress(PipelineContext $context, string $phase): void
    {
        if ($this->canLog()) {
            Log::info('estimate_generation.plan_work_items_progress', [
                'session_id' => $context->sessionId,
                'project_id' => $context->projectId,
                'phase' => $phase,
            ]);
        }
    }

    /** @param array<string, mixed> $metadata @return array<string, int> */
    private function boundedProgressMetadata(array $metadata): array
    {
        $allowed = ['intents_count', 'intent_index', 'candidate_count', 'norms_count', 'resource_rows_count', 'abstract_resource_rows_count', 'requirements_count', 'supplementary_materials_count'];
        $result = [];
        foreach ($allowed as $key) {
            if (is_int($metadata[$key] ?? null)) {
                $result[$key] = max(0, min($metadata[$key], 100_000));
            }
        }

        return $result;
    }
}
