<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Learning;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationFeedback;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNorm;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use App\Models\Estimate;
use App\Models\ImportSession;

final class EstimateGenerationLearningRecorder
{
    public function __construct(
        private readonly EstimateLearningExampleExtractor $extractor,
        private readonly WorkIntentClassifier $workIntentClassifier,
    ) {}

    public function recordImportedEstimate(Estimate $estimate, ?ImportSession $importSession = null): int
    {
        $created = 0;

        foreach ($this->extractor->extractFromImportedEstimate($estimate, $importSession) as $example) {
            $created += $this->record($example);
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $originalWorkItem
     * @param array<string, mixed> $selectedWorkItem
     * @param array<string, mixed> $context
     */
    public function recordUserSelection(
        EstimateGenerationSession $session,
        array $originalWorkItem,
        array $selectedWorkItem,
        int $normId,
        array $context
    ): int {
        $norm = EstimateNorm::query()->with('collection')->find($normId);

        if (!$norm instanceof EstimateNorm) {
            return 0;
        }

        $workItemKey = $this->workItemKey($originalWorkItem);
        $packageItem = $this->findPackageItem($session, $workItemKey);
        $workName = $this->workName($originalWorkItem);
        $workUnit = $this->nullableString($selectedWorkItem['unit'] ?? $originalWorkItem['unit'] ?? null);
        $intent = $this->workIntentClassifier->classify([
            'name' => $workName,
            'unit' => $workUnit,
        ], $context);

        return $this->record([
            'organization_id' => (int) $session->organization_id,
            'project_id' => $session->project_id !== null ? (int) $session->project_id : null,
            'source_type' => 'user_selection',
            'source_entity_type' => $packageItem instanceof EstimateGenerationPackageItem
                ? 'estimate_generation_package_item'
                : 'estimate_generation_work_item',
            'source_entity_id' => $packageItem?->id,
            'generation_session_id' => (int) $session->id,
            'generation_package_item_id' => $packageItem?->id,
            'work_name' => $workName,
            'work_unit' => $workUnit,
            'work_quantity' => $this->nullableFloat($selectedWorkItem['quantity'] ?? $originalWorkItem['quantity'] ?? null),
            'work_intent' => $this->intentPayload($intent),
            'normative_dataset_version_id' => $norm->collection?->dataset_version_id !== null
                ? (int) $norm->collection->dataset_version_id
                : null,
            'estimate_norm_id' => (int) $norm->id,
            'norm_code' => $this->normalizeNormCode((string) $norm->code),
            'normative_name' => (string) $norm->name,
            'normative_unit' => (string) $norm->unit,
            'decision_status' => (string) data_get($selectedWorkItem, 'normative_match.status', 'selected_by_user'),
            'confidence' => 1.0,
            'is_positive' => true,
            'source_quality_score' => 1.0,
            'context_payload' => [
                'work_item_key' => $workItemKey,
                'offered_candidates' => $originalWorkItem['normative_candidates'] ?? [],
                'previous_normative_match' => $originalWorkItem['normative_match'] ?? null,
                'selected_norm_id' => $normId,
                'selected_normative_code' => $this->normalizeNormCode((string) $norm->code),
                'quantity_snapshot' => $this->quantitySnapshot($selectedWorkItem, $originalWorkItem),
                'local_estimate_title' => $context['local_estimate_title'] ?? null,
                'section_title' => $context['section_title'] ?? null,
            ],
            'source_refs' => [[
                'type' => 'estimate_generation_session',
                'session_id' => (int) $session->id,
                'package_item_id' => $packageItem?->id,
            ]],
            'quality_flags' => ['user_selected'],
            'accepted_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $originalWorkItem
     * @param array<string, mixed> $selectedWorkItem
     * @param array<string, mixed> $context
     */
    public function recordSupersededSelection(
        EstimateGenerationSession $session,
        array $originalWorkItem,
        array $selectedWorkItem,
        int $selectedNormId,
        array $context
    ): int {
        $previousNormId = $this->nullableInt(
            data_get($originalWorkItem, 'normative_match.norm_id')
            ?? data_get($originalWorkItem, 'normative_match.id')
        );

        if ($previousNormId === null || $previousNormId === $selectedNormId) {
            return 0;
        }

        $norm = EstimateNorm::query()->with('collection')->find($previousNormId);

        if (!$norm instanceof EstimateNorm) {
            return 0;
        }

        $workItemKey = $this->workItemKey($originalWorkItem);
        $packageItem = $this->findPackageItem($session, $workItemKey);
        $workName = $this->workName($originalWorkItem);
        $workUnit = $this->nullableString($selectedWorkItem['unit'] ?? $originalWorkItem['unit'] ?? null);
        $intent = $this->workIntentClassifier->classify([
            'name' => $workName,
            'unit' => $workUnit,
        ], $context);

        return $this->record([
            'organization_id' => (int) $session->organization_id,
            'project_id' => $session->project_id !== null ? (int) $session->project_id : null,
            'source_type' => 'user_rejection',
            'source_entity_type' => $packageItem instanceof EstimateGenerationPackageItem
                ? 'estimate_generation_package_item'
                : 'estimate_generation_work_item',
            'source_entity_id' => $packageItem?->id,
            'generation_session_id' => (int) $session->id,
            'generation_package_item_id' => $packageItem?->id,
            'work_name' => $workName,
            'work_unit' => $workUnit,
            'work_quantity' => $this->nullableFloat($selectedWorkItem['quantity'] ?? $originalWorkItem['quantity'] ?? null),
            'work_intent' => $this->intentPayload($intent),
            'normative_dataset_version_id' => $norm->collection?->dataset_version_id !== null
                ? (int) $norm->collection->dataset_version_id
                : null,
            'estimate_norm_id' => (int) $norm->id,
            'norm_code' => $this->normalizeNormCode((string) $norm->code),
            'normative_name' => (string) $norm->name,
            'normative_unit' => (string) $norm->unit,
            'decision_status' => 'superseded_by_user_selection',
            'confidence' => 1.0,
            'is_positive' => false,
            'source_quality_score' => 1.0,
            'context_payload' => [
                'work_item_key' => $workItemKey,
                'rejected_norm_id' => $previousNormId,
                'rejected_normative_code' => $this->normalizeNormCode((string) $norm->code),
                'selected_norm_id' => $selectedNormId,
                'selected_normative_code' => $this->nullableString((string) data_get($selectedWorkItem, 'normative_match.code')),
                'quantity_snapshot' => $this->quantitySnapshot($selectedWorkItem, $originalWorkItem),
                'offered_candidates' => $originalWorkItem['normative_candidates'] ?? [],
                'previous_normative_match' => $originalWorkItem['normative_match'] ?? null,
                'local_estimate_title' => $context['local_estimate_title'] ?? null,
                'section_title' => $context['section_title'] ?? null,
            ],
            'source_refs' => [[
                'type' => 'estimate_generation_session',
                'session_id' => (int) $session->id,
                'package_item_id' => $packageItem?->id,
            ]],
            'quality_flags' => ['user_replaced'],
            'accepted_at' => now(),
        ]);
    }

    public function recordFeedbackDecision(EstimateGenerationSession $session, EstimateGenerationFeedback $feedback): int
    {
        if ($feedback->feedback_type === 'normative_confirmation') {
            return $this->recordUserConfirmation($session, $feedback);
        }

        if ($feedback->feedback_type === 'quantity_confirmation') {
            return $this->recordQuantityConfirmation($session, $feedback);
        }

        return $this->recordUserRejection($session, $feedback);
    }

    public function recordUserRejection(EstimateGenerationSession $session, EstimateGenerationFeedback $feedback): int
    {
        if (!in_array($feedback->feedback_type, ['normative_rejection', 'normative_correction'], true)) {
            return 0;
        }

        $payload = is_array($feedback->payload) ? $feedback->payload : [];
        $workItemKey = $feedback->work_item_key;
        $workItem = $this->findDraftWorkItem($session, $workItemKey);
        $norm = $this->normFromPayload($payload);
        $normCode = $this->normalizeNormCode((string) ($payload['normative_code'] ?? $norm?->code ?? ''));

        if ($normCode === '') {
            return 0;
        }

        $packageItem = $this->findPackageItem($session, $workItemKey);
        $workName = $this->workName($workItem);
        $workUnit = $this->nullableString($workItem['unit'] ?? null);
        $intent = $this->workIntentClassifier->classify([
            'name' => $workName,
            'unit' => $workUnit,
        ], [
            'section_title' => $this->sectionTitleForWorkItem($session, $workItemKey),
        ]);

        return $this->record([
            'organization_id' => (int) $session->organization_id,
            'project_id' => $session->project_id !== null ? (int) $session->project_id : null,
            'source_type' => 'user_rejection',
            'source_entity_type' => 'estimate_generation_feedback',
            'source_entity_id' => (int) $feedback->id,
            'generation_session_id' => (int) $session->id,
            'generation_package_item_id' => $packageItem?->id,
            'work_name' => $workName,
            'work_unit' => $workUnit,
            'work_quantity' => $this->nullableFloat($workItem['quantity'] ?? null),
            'work_intent' => $this->intentPayload($intent),
            'normative_dataset_version_id' => $norm?->collection?->dataset_version_id !== null
                ? (int) $norm->collection->dataset_version_id
                : null,
            'estimate_norm_id' => $norm?->id,
            'norm_code' => $normCode,
            'normative_name' => $norm?->name,
            'normative_unit' => $norm?->unit,
            'decision_status' => 'rejected_by_user',
            'confidence' => 1.0,
            'is_positive' => false,
            'source_quality_score' => 1.0,
            'context_payload' => [
                'work_item_key' => $workItemKey,
                'rejected_norm_id' => $payload['norm_id'] ?? null,
                'rejected_normative_code' => $normCode,
                'selection_source' => $payload['selection_source'] ?? null,
                'reason' => $payload['reason'] ?? null,
                'comments' => $feedback->comments,
                'offered_candidates' => $workItem['normative_candidates'] ?? [],
                'normative_match' => $workItem['normative_match'] ?? null,
            ],
            'source_refs' => [[
                'type' => 'estimate_generation_feedback',
                'feedback_id' => (int) $feedback->id,
                'session_id' => (int) $session->id,
                'package_item_id' => $packageItem?->id,
            ]],
            'quality_flags' => ['user_rejected'],
            'accepted_at' => now(),
        ]);
    }

    private function recordUserConfirmation(EstimateGenerationSession $session, EstimateGenerationFeedback $feedback): int
    {
        $payload = is_array($feedback->payload) ? $feedback->payload : [];
        $workItemKey = $feedback->work_item_key;
        $workItem = $this->findDraftWorkItem($session, $workItemKey);
        $norm = $this->normFromPayload($payload);
        $normCode = $this->normalizeNormCode((string) (
            $payload['normative_code']
            ?? data_get($workItem, 'normative_match.code')
            ?? $norm?->code
            ?? ''
        ));

        if (!$norm instanceof EstimateNorm && $normCode !== '') {
            $norm = EstimateNorm::query()
                ->with('collection')
                ->where('code', $normCode)
                ->latest('id')
                ->first();
        }

        if (!$norm instanceof EstimateNorm || $normCode === '') {
            return 0;
        }

        $packageItem = $this->findPackageItem($session, $workItemKey);
        $workName = $this->workName($workItem);
        $workUnit = $this->nullableString($workItem['unit'] ?? null);
        $intent = $this->workIntentClassifier->classify([
            'name' => $workName,
            'unit' => $workUnit,
        ], [
            'section_title' => $this->sectionTitleForWorkItem($session, $workItemKey),
        ]);

        return $this->record([
            'organization_id' => (int) $session->organization_id,
            'project_id' => $session->project_id !== null ? (int) $session->project_id : null,
            'source_type' => 'manual_review_choice',
            'source_entity_type' => 'estimate_generation_feedback',
            'source_entity_id' => (int) $feedback->id,
            'generation_session_id' => (int) $session->id,
            'generation_package_item_id' => $packageItem?->id,
            'work_name' => $workName,
            'work_unit' => $workUnit,
            'work_quantity' => $this->nullableFloat($workItem['quantity'] ?? null),
            'work_intent' => $this->intentPayload($intent),
            'normative_dataset_version_id' => $norm->collection?->dataset_version_id !== null
                ? (int) $norm->collection->dataset_version_id
                : null,
            'estimate_norm_id' => (int) $norm->id,
            'norm_code' => $normCode,
            'normative_name' => (string) $norm->name,
            'normative_unit' => (string) $norm->unit,
            'decision_status' => 'confirmed_by_user',
            'confidence' => 1.0,
            'is_positive' => true,
            'source_quality_score' => 1.0,
            'context_payload' => [
                'work_item_key' => $workItemKey,
                'confirmed_norm_id' => $payload['norm_id'] ?? data_get($workItem, 'normative_match.norm_id'),
                'confirmed_normative_code' => $normCode,
                'comments' => $feedback->comments,
                'normative_match' => $workItem['normative_match'] ?? null,
                'quantity_snapshot' => $this->quantitySnapshot($workItem, $workItem),
            ],
            'source_refs' => [[
                'type' => 'estimate_generation_feedback',
                'feedback_id' => (int) $feedback->id,
                'session_id' => (int) $session->id,
                'package_item_id' => $packageItem?->id,
            ]],
            'quality_flags' => ['user_confirmed_normative'],
            'accepted_at' => now(),
        ]);
    }

    private function recordQuantityConfirmation(EstimateGenerationSession $session, EstimateGenerationFeedback $feedback): int
    {
        $payload = is_array($feedback->payload) ? $feedback->payload : [];
        $workItemKey = $feedback->work_item_key;
        $workItem = $this->findDraftWorkItem($session, $workItemKey);
        $quantity = $this->nullableFloat($workItem['quantity'] ?? $payload['quantity'] ?? null);

        if ($quantity === null || $quantity <= 0) {
            return 0;
        }

        $packageItem = $this->findPackageItem($session, $workItemKey);
        $workName = $this->workName($workItem);
        $workUnit = $this->nullableString($workItem['unit'] ?? $payload['unit'] ?? null);
        $quantityKey = $this->quantityKey($workItem, $workItemKey);
        $intent = $this->workIntentClassifier->classify([
            'name' => $workName,
            'unit' => $workUnit,
        ], [
            'section_title' => $this->sectionTitleForWorkItem($session, $workItemKey),
        ]);

        return $this->record([
            'organization_id' => (int) $session->organization_id,
            'project_id' => $session->project_id !== null ? (int) $session->project_id : null,
            'source_type' => 'manual_quantity_confirmation',
            'source_entity_type' => 'estimate_generation_feedback',
            'source_entity_id' => (int) $feedback->id,
            'generation_session_id' => (int) $session->id,
            'generation_package_item_id' => $packageItem?->id,
            'work_name' => $workName,
            'work_unit' => $workUnit,
            'work_quantity' => $quantity,
            'work_intent' => $this->intentPayload($intent),
            'estimate_norm_id' => null,
            'norm_code' => $this->quantityLearningCode($quantityKey),
            'normative_name' => null,
            'normative_unit' => null,
            'decision_status' => 'quantity_confirmed_by_user',
            'confidence' => 1.0,
            'is_positive' => true,
            'source_quality_score' => 1.0,
            'context_payload' => [
                'work_item_key' => $workItemKey,
                'quantity_key' => $quantityKey,
                'quantity_formula' => $this->nullableString($workItem['quantity_formula'] ?? null),
                'quantity_basis' => $this->nullableString($workItem['quantity_basis'] ?? $payload['quantity_basis'] ?? null),
                'quantity_snapshot' => $this->quantitySnapshot($workItem, $payload),
                'quantity_feedback' => data_get($workItem, 'metadata.quantity_feedback'),
                'calculation_basis' => $this->nullableString(data_get($workItem, 'metadata.calculation_basis')),
                'comments' => $feedback->comments,
                'section_title' => $this->sectionTitleForWorkItem($session, $workItemKey),
            ],
            'source_refs' => $this->quantitySourceRefs($session, $feedback, $packageItem, $workItem),
            'quality_flags' => ['user_confirmed_quantity', 'manual_quantity_review'],
            'accepted_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function record(array $attributes): int
    {
        $example = EstimateGenerationLearningExample::query()->firstOrNew([
            'source_type' => (string) $attributes['source_type'],
            'source_entity_type' => $attributes['source_entity_type'] ?? null,
            'source_entity_id' => $attributes['source_entity_id'] ?? null,
            'norm_code' => (string) $attributes['norm_code'],
        ]);

        if ($example->exists && !$this->canReplace($example, $attributes)) {
            return 0;
        }

        $wasNew = !$example->exists;
        $example->fill($attributes);
        $example->save();

        return $wasNew ? 1 : 0;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function canReplace(EstimateGenerationLearningExample $example, array $attributes): bool
    {
        $currentQuality = (float) ($example->source_quality_score ?? 0);
        $nextQuality = (float) ($attributes['source_quality_score'] ?? 0);

        if ($example->is_positive && $currentQuality > $nextQuality) {
            return false;
        }

        return true;
    }

    private function findPackageItem(EstimateGenerationSession $session, ?string $workItemKey): ?EstimateGenerationPackageItem
    {
        if ($workItemKey === null || $workItemKey === '') {
            return null;
        }

        return EstimateGenerationPackageItem::query()
            ->where('key', $workItemKey)
            ->whereHas('package', static function ($query) use ($session): void {
                $query->where('session_id', $session->id);
            })
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function findDraftWorkItem(EstimateGenerationSession $session, ?string $workItemKey): array
    {
        if ($workItemKey === null || $workItemKey === '') {
            return [];
        }

        foreach (($session->draft_payload['local_estimates'] ?? []) as $localEstimate) {
            foreach (($localEstimate['sections'] ?? []) as $section) {
                foreach (($section['work_items'] ?? []) as $workItem) {
                    if ((string) ($workItem['key'] ?? '') === $workItemKey) {
                        return is_array($workItem) ? $workItem : [];
                    }
                }
            }
        }

        return [];
    }

    private function sectionTitleForWorkItem(EstimateGenerationSession $session, ?string $workItemKey): ?string
    {
        foreach (($session->draft_payload['local_estimates'] ?? []) as $localEstimate) {
            foreach (($localEstimate['sections'] ?? []) as $section) {
                foreach (($section['work_items'] ?? []) as $workItem) {
                    if ((string) ($workItem['key'] ?? '') === $workItemKey) {
                        return $this->nullableString($section['title'] ?? null);
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function normFromPayload(array $payload): ?EstimateNorm
    {
        $normId = $payload['norm_id'] ?? null;

        if ($normId !== null && $normId !== '') {
            $norm = EstimateNorm::query()->with('collection')->find((int) $normId);

            if ($norm instanceof EstimateNorm) {
                return $norm;
            }
        }

        $normCode = $this->normalizeNormCode((string) ($payload['normative_code'] ?? ''));

        if ($normCode === '') {
            return null;
        }

        return EstimateNorm::query()
            ->with('collection')
            ->where('code', $normCode)
            ->latest('id')
            ->first();
    }

    /**
     * @param array<string, mixed> $workItem
     */
    private function workItemKey(array $workItem): ?string
    {
        return $this->nullableString($workItem['key'] ?? null);
    }

    /**
     * @param array<string, mixed> $workItem
     */
    private function workName(array $workItem): string
    {
        return trim((string) ($workItem['normative_search_text'] ?? $workItem['name'] ?? 'Позиция сметы'));
    }

    private function normalizeNormCode(string $code): string
    {
        $code = trim($code);
        $code = preg_replace('/^[^\d]*/u', '', $code) ?? $code;
        $code = preg_replace('/\s+/u', '', $code) ?? $code;

        return trim($code);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $workItem
     */
    private function quantityKey(array $workItem, ?string $workItemKey): string
    {
        $quantityKey = $this->nullableString(data_get($workItem, 'metadata.quantity_key'))
            ?? $this->nullableString($workItem['quantity_formula'] ?? null)
            ?? $this->nullableString($workItemKey)
            ?? 'unknown';

        return $quantityKey;
    }

    private function quantityLearningCode(string $quantityKey): string
    {
        return EstimateGenerationQuantityLearningKey::fromQuantityKey($quantityKey);
    }

    /**
     * @param array<string, mixed> $workItem
     * @return array<int, array<string, mixed>>
     */
    private function quantitySourceRefs(
        EstimateGenerationSession $session,
        EstimateGenerationFeedback $feedback,
        ?EstimateGenerationPackageItem $packageItem,
        array $workItem
    ): array {
        $sourceRefs = [[
            'type' => 'estimate_generation_feedback',
            'feedback_id' => (int) $feedback->id,
            'session_id' => (int) $session->id,
            'package_item_id' => $packageItem?->id,
        ]];

        foreach ($this->arrayValues($workItem['source_refs'] ?? []) as $sourceRef) {
            if (is_array($sourceRef)) {
                $sourceRefs[] = $sourceRef;
            }
        }

        return $sourceRefs;
    }

    /**
     * @return array<int, mixed>
     */
    private function arrayValues(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param array<string, mixed> $selectedWorkItem
     * @param array<string, mixed> $originalWorkItem
     * @return array<string, mixed>|null
     */
    private function quantitySnapshot(array $selectedWorkItem, array $originalWorkItem): ?array
    {
        $quantityFeedback = data_get($selectedWorkItem, 'metadata.quantity_feedback');

        if (!is_array($quantityFeedback)) {
            $quantityFeedback = data_get($originalWorkItem, 'metadata.quantity_feedback');
        }

        if (!is_array($quantityFeedback)) {
            $quantityFeedback = [];
        }

        $quantity = $this->nullableFloat(
            data_get($selectedWorkItem, 'quantity')
            ?? data_get($originalWorkItem, 'quantity')
            ?? ($quantityFeedback['quantity'] ?? null)
        );
        $unit = $this->nullableString(
            data_get($selectedWorkItem, 'unit')
            ?? data_get($originalWorkItem, 'unit')
            ?? ($quantityFeedback['unit'] ?? null)
        );
        $quantityBasis = $this->nullableString(
            data_get($selectedWorkItem, 'quantity_basis')
            ?? data_get($originalWorkItem, 'quantity_basis')
            ?? ($quantityFeedback['quantity_basis'] ?? null)
        );

        if ($quantity === null && $unit === null && $quantityBasis === null && $quantityFeedback === []) {
            return null;
        }

        return array_filter([
            'quantity' => $quantity,
            'unit' => $unit,
            'quantity_basis' => $quantityBasis,
            'confirmed_by_user' => ($quantityFeedback['status'] ?? null) === 'confirmed_by_user',
            'feedback' => $quantityFeedback !== [] ? $quantityFeedback : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function intentPayload(WorkIntentData $intent): array
    {
        return [
            'scope' => $intent->scope,
            'action' => $intent->action,
            'object' => $intent->object,
            'material' => $intent->material,
            'system' => $intent->system,
            'expected_dimensions' => $intent->expectedDimensions,
            'signals' => $intent->signals,
            'confidence' => $intent->confidence,
        ];
    }
}
