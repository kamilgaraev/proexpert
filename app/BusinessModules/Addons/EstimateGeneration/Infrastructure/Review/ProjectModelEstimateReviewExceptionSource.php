<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Review;

use App\BusinessModules\Addons\EstimateGeneration\Application\Review\EstimateReviewExceptionSource;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Conflict;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessFinding;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendation;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationReviewItemService;

final class ProjectModelEstimateReviewExceptionSource implements EstimateReviewExceptionSource
{
    public function __construct(
        private readonly ProjectModelRepository $models,
        private readonly EstimateGenerationReviewItemService $draftReview,
    ) {}

    public function current(EstimateGenerationSession $session, int $limit): array
    {
        $organizationId = (int) $session->organization_id;
        $projectId = (int) $session->project_id;
        $sessionId = (int) $session->getKey();
        $limit = max(1, min($limit, 1001));
        $items = [];
        $truncated = false;

        $snapshot = $this->models->snapshot($organizationId, $projectId, $sessionId, $limit);
        $evidence = [];
        foreach ($snapshot->evidence as $locator) {
            $evidence[$locator->id] = $locator;
        }
        $factEvidence = [];
        foreach ($snapshot->facts as $fact) {
            $factEvidence[$fact->id] = $fact->evidenceIds;
        }
        foreach ($snapshot->conflicts as $conflict) {
            $items[] = $this->conflict($conflict, $evidence);
            if (count($items) >= $limit) {
                return ['items' => $items, 'truncated' => true];
            }
        }

        $completeness = $this->models->currentCompleteness($organizationId, $projectId, $sessionId);
        foreach ($completeness['findings'] ?? [] as $finding) {
            if (! $finding instanceof CompletenessFinding || $finding->status === 'resolved') {
                continue;
            }
            $items[] = $this->completeness($finding, $completeness, $evidence, $factEvidence);
            if (count($items) >= $limit) {
                return ['items' => $items, 'truncated' => true];
            }
        }

        $technology = $this->models->currentTechnologyRecommendations($organizationId, $projectId, $sessionId);
        foreach ($technology['recommendations'] ?? [] as $recommendation) {
            if (! $recommendation instanceof TechnologyRecommendation) {
                continue;
            }
            $items[] = $this->technology($recommendation, $technology, $evidence);
            if (count($items) >= $limit) {
                return ['items' => $items, 'truncated' => true];
            }
        }

        $draftPage = 1;
        do {
            $draft = $this->draftReview->forSession($session, ['page' => $draftPage, 'per_page' => min(100, $limit)]);
            foreach ($draft['items'] as $reviewItem) {
                $items[] = $this->draft($reviewItem, $session);
                if (count($items) >= $limit) {
                    $truncated = true;
                    break 2;
                }
            }
            $draftPage++;
        } while ($draftPage <= (int) ($draft['meta']['last_page'] ?? 1));

        return ['items' => $items, 'truncated' => $truncated];
    }

    /** @param array<string, Evidence> $evidence */
    private function conflict(Conflict $conflict, array $evidence): array
    {
        $ids = [];
        $confidence = 1.0;
        $floor = null;
        $room = null;
        foreach ($conflict->facts as $fact) {
            $ids = [...$ids, ...$fact->evidenceIds];
            $confidence = min($confidence, $fact->confidence);
            $floor ??= is_scalar($fact->value) && $fact->type === 'floor' ? (string) $fact->value : null;
            $room ??= is_scalar($fact->value) && $fact->type === 'room' ? (string) $fact->value : null;
        }

        return [
            'id' => $conflict->id, 'type' => 'conflict',
            'type_label' => trans_message('estimate_generation.review_types.conflict'),
            'title' => $conflict->reason, 'blocking' => true, 'severity' => 'blocking',
            'confidence' => number_format($confidence, 4, '.', ''),
            'cost_impact' => ['state' => 'unknown', 'amount' => null],
            'floor' => $floor, 'room' => $room, 'section' => null, 'origin' => 'stage4',
            'unresolved_type' => 'conflict', 'codes' => ['project_model_conflict'],
            'provenance' => ['source_version' => $conflict->sourceVersion, 'stage' => 'stage4', 'stable_key' => $conflict->id],
            'locators' => $this->locators($ids, $evidence), 'recommendation' => null,
        ];
    }

    /** @param array<string, mixed> $projection @param array<string, Evidence> $evidence @param array<string, array<int, string>> $factEvidence */
    private function completeness(CompletenessFinding $finding, array $projection, array $evidence, array $factEvidence): array
    {
        $lowConfidence = $finding->confidence < 0.6;
        $evidenceIds = [];
        foreach ($finding->evidenceFactIds as $factId) {
            if (is_string($factId)) {
                $evidenceIds = [...$evidenceIds, ...($factEvidence[$factId] ?? [])];
            }
        }

        return [
            'id' => $finding->stableKey,
            'type' => $lowConfidence ? 'low_confidence' : 'missing_required_data',
            'type_label' => trans_message($lowConfidence ? 'estimate_generation.review_types.low_confidence' : 'estimate_generation.review_types.missing_required_data'),
            'title' => trans_message('estimate_generation.planning.completeness.'.$finding->ruleId.'.impact'),
            'blocking' => $finding->severity === 'blocking',
            'severity' => in_array($finding->severity, ['blocking', 'warning', 'optional'], true) ? $finding->severity : 'warning',
            'confidence' => number_format($finding->confidence, 4, '.', ''),
            'cost_impact' => $this->costImpact($finding->workPackage?->toArray()['regional_price_availability'] ?? null),
            'floor' => $finding->applicability['floor'] ?? null, 'room' => $finding->applicability['room'] ?? null,
            'section' => $finding->applicability['section'] ?? null, 'origin' => 'stage5',
            'unresolved_type' => $finding->classification, 'codes' => [$finding->ruleId, $finding->classification],
            'provenance' => [
                'source_version' => $projection['source_version'] ?? null, 'catalog_version' => $projection['catalog_version'] ?? null,
                'stage' => 'stage5', 'stable_key' => $finding->stableKey,
            ],
            'locators' => $this->locators($evidenceIds, $evidence),
            'recommendation' => $finding->workPackage === null ? null : ['work_packages' => [$finding->workPackage->toArray()]],
        ];
    }

    /** @param array<string, mixed> $projection @param array<string, Evidence> $evidence */
    private function technology(TechnologyRecommendation $recommendation, array $projection, array $evidence): array
    {
        $selected = $recommendation->recommendedOption();
        $options = array_map(static fn ($option): array => $option->toArray(), $recommendation->options);
        $evidenceIds = [];
        foreach ($recommendation->options as $option) {
            foreach ($option->applicabilityEvidence as $id) {
                if (is_string($id)) {
                    $evidenceIds[] = $id;
                }
            }
        }

        return [
            'id' => $recommendation->decisionKey, 'type' => 'technology_recommendation',
            'type_label' => trans_message('estimate_generation.review_types.technology_recommendation'),
            'title' => $recommendation->question, 'blocking' => false, 'severity' => 'optional',
            'confidence' => $selected === null ? '0' : number_format(max(0, min(1, $selected->score / 100)), 4, '.', ''),
            'cost_impact' => $this->costImpact($selected?->system->costPreview ?? null),
            'floor' => null, 'room' => null, 'section' => null, 'origin' => 'stage5',
            'unresolved_type' => 'technology_choice', 'codes' => ['technology_recommendation'],
            'provenance' => [
                'source_version' => $recommendation->sourceVersion, 'catalog_version' => $recommendation->catalogVersion,
                'stage' => 'stage5', 'stable_key' => $recommendation->decisionKey,
            ],
            'locators' => $this->locators($evidenceIds, $evidence),
            'recommendation' => [
                'decision_key' => $recommendation->decisionKey, 'question' => $recommendation->question,
                'rationale' => $selected?->explanation, 'applicability' => $selected === null ? null : [
                    'status' => $selected->applicabilityStatus, 'reasons' => $selected->applicabilityReasons,
                ],
                'evidence' => array_slice($evidenceIds, 0, 16), 'alternatives' => array_slice($options, 0, 8),
                'work_packages' => array_slice($selected?->system->works ?? [], 0, 32),
                'response_options' => array_slice($recommendation->responseOptions, 0, 16), 'selected_option' => null,
            ],
        ];
    }

    /** @param array<string, mixed> $item */
    private function draft(array $item, EstimateGenerationSession $session): array
    {
        $reasonCodes = array_values(array_filter(array_map('strval', is_array($item['reason_codes'] ?? null) ? $item['reason_codes'] : [])));
        $lowConfidence = in_array('normative_match_low_confidence', $reasonCodes, true) || in_array('price_review_required', $reasonCodes, true);
        $workItem = is_array($item['work_item'] ?? null) ? $item['work_item'] : [];

        return [
            'id' => (string) ($item['key'] ?? $item['work_item_key'] ?? ''),
            'type' => $lowConfidence ? 'low_confidence' : 'missing_required_data',
            'type_label' => trans_message($lowConfidence ? 'estimate_generation.review_types.low_confidence' : 'estimate_generation.review_types.missing_required_data'),
            'title' => (string) ($workItem['name'] ?? $item['message'] ?? trans_message('estimate_generation.review_item')),
            'blocking' => ($item['severity'] ?? null) === 'blocking', 'severity' => (string) ($item['severity'] ?? 'warning'),
            'confidence' => $this->confidence($workItem), 'cost_impact' => $this->costImpact($workItem['total_cost'] ?? null),
            'floor' => data_get($workItem, 'metadata.floor'), 'room' => data_get($workItem, 'metadata.room'),
            'section' => $item['section_title'] ?? null, 'origin' => str_starts_with((string) ($item['key'] ?? ''), 'stage6:') ? 'stage6' : 'draft',
            'unresolved_type' => $item['required_action'] ?? null, 'codes' => $reasonCodes,
            'provenance' => [
                'draft_version' => (int) $session->state_version, 'stage' => str_starts_with((string) ($item['key'] ?? ''), 'stage6:') ? 'stage6' : 'draft',
                'stable_key' => $item['work_item_key'] ?? $item['key'] ?? null,
            ],
            'locators' => array_map($this->sourceRefLocator(...), array_slice(is_array($item['source_refs'] ?? null) ? $item['source_refs'] : [], 0, 16)),
            'recommendation' => null,
        ];
    }

    /** @param array<int, string> $ids @param array<string, Evidence> $evidence */
    private function locators(array $ids, array $evidence): array
    {
        $result = [];
        foreach (array_slice(array_values(array_unique($ids)), 0, 16) as $id) {
            if (isset($evidence[$id])) {
                $result[] = $this->evidenceLocator($evidence[$id]);
            }
        }

        return $result;
    }

    private function evidenceLocator(Evidence $evidence): array
    {
        preg_match('/(\d+)\z/', $evidence->sourceArtifactId, $matches);

        return [
            'artifact_id' => isset($matches[1]) ? (int) $matches[1] : 0, 'source_version' => $evidence->sourceVersion,
            'representation_kind' => $evidence->nativeReference !== null ? 'native' : 'page',
            'page' => $evidence->page,
            'region' => $evidence->region,
            'native_reference' => $evidence->nativeReference,
        ];
    }

    /** @param array<string, mixed> $source */
    private function sourceRefLocator(array $source): array
    {
        return [
            'artifact_id' => (int) ($source['artifact_id'] ?? $source['document_id'] ?? 0),
            'representation_kind' => $source['representation_kind'] ?? null,
            'source_version' => $source['source_version'] ?? null,
            'page' => $source['page'] ?? $source['page_number'] ?? null,
            'sheet' => $source['sheet'] ?? null,
            'region' => $source['region'] ?? $source['bbox'] ?? null,
            'native_reference' => $source['native_reference'] ?? $source['filename'] ?? null,
        ];
    }

    private function confidence(array $workItem): string
    {
        $value = data_get($workItem, 'normative_match.confidence', $workItem['confidence'] ?? 0);

        return is_numeric($value) ? number_format(max(0, min(1, (float) $value)), 4, '.', '') : '0';
    }

    /** @return array{state: string, amount: ?string, currency?: string} */
    private function costImpact(mixed $value): array
    {
        if (is_array($value)) {
            $value = $value['amount'] ?? $value['total'] ?? $value['cost'] ?? null;
        }
        if (is_int($value) || is_string($value) && preg_match('/\A-?(?:0|[1-9]\d*)(?:\.\d{1,4})?\z/', $value) === 1) {
            return ['state' => 'known', 'amount' => (string) $value, 'currency' => 'RUB'];
        }

        return ['state' => 'unknown', 'amount' => null];
    }
}
