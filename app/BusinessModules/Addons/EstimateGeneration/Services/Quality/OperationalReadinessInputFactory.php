<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

final class OperationalReadinessInputFactory
{
    /**
     * @param  array<string, mixed>  $session
     * @param  array<string, mixed>  $documents
     * @param  array<string, mixed>  $estimate
     * @param  array<string, mixed>  $sources
     */
    public function fromAggregates(array $session, array $documents, array $estimate, array $sources): EstimatorReadinessInput
    {
        $reviewFresh = (int) ($session['review_classifier_version'] ?? 0) === ReviewSummarySnapshot::VERSION
            && preg_match('/^sha256:[0-9a-f]{64}$/', (string) ($session['review_content_version'] ?? '')) === 1
            && hash_equals((string) $session['review_content_version'], (string) ($session['review_source_version'] ?? ''))
            && preg_match('/^sha256:[0-9a-f]{64}$/', (string) ($session['review_canonical_input_version'] ?? '')) === 1
            && hash_equals((string) $session['review_canonical_input_version'], (string) ($session['review_input_version'] ?? ''));

        $total = $this->number($session, 'quality_total_work_items');
        $operations = $this->number($session, 'quality_operation_work_items');
        $quantityReview = $this->number($session, 'quality_quantity_review_work_items');
        $zeroTotal = $this->number($session, 'quality_zero_total_calculated_work_items');

        return new EstimatorReadinessInput(
            sessionStatus: (string) ($session['status'] ?? 'draft'),
            hasDraft: $this->boolean($session['has_draft'] ?? false),
            qualityStatus: (string) ($session['quality_status'] ?? ''),
            qualityLevel: (string) ($session['quality_level'] ?? ''),
            metrics: [
                'documents_total' => $this->number($documents, 'total'),
                'documents_ready' => $this->number($documents, 'ready'),
                'documents_pending' => $this->number($documents, 'pending'),
                'documents_action_required' => $this->number($documents, 'action_required'),
                'facts' => $this->number($sources, 'facts_count'),
                'drawing_elements' => $this->number($sources, 'drawings_count'),
                'quantity_takeoffs' => $this->number($sources, 'quantities_count'),
                'scope_inferences' => $this->number($sources, 'scopes_count'),
                'priced_work_items' => max($this->number($session, 'quality_priced_work_items') - $zeroTotal, 0),
                'priced_work_items_total' => max($total - $operations - $quantityReview, 0),
                'operation_work_items' => $operations,
                'quantity_review_work_items' => $quantityReview,
                'not_calculated_work_items' => $this->number($session, 'quality_not_calculated_work_items') + $zeroTotal,
                'zero_total_calculated_work_items' => $zeroTotal,
                'safe_norm_required_work_items' => $this->number($session, 'quality_safe_norm_required_work_items'),
                'duplicate_work_items' => $this->number($session, 'quality_duplicate_work_items'),
                'normative_requires_review' => $this->number($session, 'quality_normative_requires_review'),
                'review_items_total' => $this->number($session, 'review_total'),
                'review_items_blocking' => $reviewFresh ? $this->number($session, 'review_blocking') : 0,
                'review_items_warning' => $this->number($session, 'review_warning'),
                'review_items_optional' => $this->number($session, 'review_optional'),
                'review_summary_stale' => ! $reviewFresh && (bool) ($session['has_draft'] ?? false) ? 1 : 0,
                'problem_flags' => $this->number($session, 'problem_flags_count'),
            ],
        );
    }

    /** @param array<string, mixed> $source */
    private function number(array $source, string $key): int
    {
        $value = $source[$key] ?? 0;

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function boolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
