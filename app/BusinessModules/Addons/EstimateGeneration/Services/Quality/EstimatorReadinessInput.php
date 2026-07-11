<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

use InvalidArgumentException;

final readonly class EstimatorReadinessInput
{
    private const METRICS = [
        'documents_total',
        'documents_ready',
        'documents_pending',
        'documents_action_required',
        'facts',
        'drawing_elements',
        'quantity_takeoffs',
        'scope_inferences',
        'priced_work_items',
        'priced_work_items_total',
        'operation_work_items',
        'quantity_review_work_items',
        'not_calculated_work_items',
        'zero_total_calculated_work_items',
        'safe_norm_required_work_items',
        'duplicate_work_items',
        'normative_requires_review',
        'review_items_total',
        'review_items_blocking',
        'review_items_warning',
        'review_items_optional',
        'review_summary_stale',
        'problem_flags',
    ];

    /** @var array<string, int> */
    public array $metrics;

    /** @param array<string, int|numeric-string> $metrics */
    public function __construct(
        public string $sessionStatus,
        public bool $hasDraft,
        public string $qualityStatus,
        public string $qualityLevel,
        array $metrics,
    ) {
        if (trim($sessionStatus) === '') {
            throw new InvalidArgumentException('estimate_generation.readiness_session_status_required');
        }

        $normalized = [];
        foreach (self::METRICS as $metric) {
            $value = $metrics[$metric] ?? 0;
            if (! is_numeric($value) || (int) $value < 0) {
                throw new InvalidArgumentException('estimate_generation.readiness_metric_invalid');
            }
            $normalized[$metric] = (int) $value;
        }
        $this->metrics = $normalized;
    }
}
