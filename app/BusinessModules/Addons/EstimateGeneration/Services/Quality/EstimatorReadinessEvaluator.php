<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use Throwable;

use function function_exists;
use function trans_message;

final class EstimatorReadinessEvaluator
{
    /** @return array<string, mixed> */
    public function evaluate(EstimatorReadinessInput $input): array
    {
        $metrics = $input->metrics;
        $blockers = $this->blockers($input);
        $warnings = $this->warnings($metrics);
        $status = $this->status($input, $blockers);

        return [
            'status' => $status,
            'can_generate' => $metrics['documents_ready'] > 0
                && $metrics['documents_pending'] === 0
                && $metrics['documents_action_required'] === 0,
            'can_apply' => $status === 'ready_to_apply' && $blockers === [],
            'next_action' => $this->nextAction($status),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'metrics' => $metrics,
        ];
    }

    /** @return list<array{code: string, message_key: string, message: string}> */
    private function blockers(EstimatorReadinessInput $input): array
    {
        $metrics = $input->metrics;
        $blockers = [];
        if ($metrics['documents_total'] === 0) {
            $blockers[] = $this->issue('no_documents', 'estimate_generation.readiness_blocker_no_documents');
        }
        if ($metrics['documents_pending'] > 0) {
            $blockers[] = $this->issue('documents_pending', 'estimate_generation.readiness_blocker_documents_pending');
        }
        if ($metrics['documents_action_required'] > 0) {
            $blockers[] = $this->issue('documents_require_review', 'estimate_generation.readiness_blocker_documents_require_review');
        }
        if ($input->hasDraft && $metrics['priced_work_items_total'] <= 0) {
            $blockers[] = $this->issue('no_priced_positions', 'estimate_generation.readiness_blocker_no_priced_positions');
        }
        if ($input->hasDraft && $metrics['normative_requires_review'] > 0) {
            $blockers[] = $this->issue('norms_require_review', 'estimate_generation.readiness_blocker_norms_require_review');
        }
        if ($input->hasDraft && $metrics['quantity_review_work_items'] > 0) {
            $blockers[] = $this->issue('quantities_require_review', 'estimate_generation.readiness_blocker_quantities_require_review');
        }
        if ($input->hasDraft && $metrics['review_items_blocking'] > 0) {
            $blockers[] = $this->issue('review_items_require_action', 'estimate_generation.readiness_blocker_review_items_require_action');
        }
        if ($input->hasDraft && $metrics['review_summary_stale'] > 0) {
            $blockers[] = $this->issue('review_summary_stale', 'estimate_generation.readiness_blocker_review_items_require_action');
        }
        if ($input->hasDraft && ($metrics['not_calculated_work_items'] > 0 || $metrics['safe_norm_required_work_items'] > 0)) {
            $blockers[] = $this->issue('prices_require_review', 'estimate_generation.readiness_blocker_prices_require_review');
        }
        if ($input->hasDraft && ($input->qualityStatus === 'review_required' || $metrics['duplicate_work_items'] > 0)) {
            $blockers[] = $this->issue('quality_requires_review', 'estimate_generation.readiness_next_review_draft');
        }
        if ($input->hasDraft && ($input->qualityStatus === 'critical' || $input->qualityLevel === 'blocked')) {
            $blockers[] = $this->issue('quality_blocked', 'estimate_generation.readiness_blocker_quality_blocked');
        }

        return $blockers;
    }

    /** @param array<string, int> $metrics @return list<array{code: string, message_key: string, message: string}> */
    private function warnings(array $metrics): array
    {
        $warnings = [];
        if ($metrics['documents_ready'] > 0 && $metrics['quantity_takeoffs'] === 0) {
            $warnings[] = $this->issue('no_quantity_takeoffs', 'estimate_generation.readiness_warning_no_quantity_takeoffs');
        }
        if ($metrics['documents_ready'] > 0 && $metrics['facts'] === 0 && $metrics['drawing_elements'] === 0) {
            $warnings[] = $this->issue('low_document_understanding', 'estimate_generation.readiness_warning_low_document_understanding');
        }

        return $warnings;
    }

    /** @param list<array{code: string, message_key: string, message: string}> $blockers */
    private function status(EstimatorReadinessInput $input, array $blockers): string
    {
        $metrics = $input->metrics;
        if ($input->sessionStatus === EstimateGenerationStatus::Applied->value) {
            return 'applied';
        }
        if ($metrics['documents_total'] === 0) {
            return 'needs_documents';
        }
        if ($metrics['documents_pending'] > 0) {
            return 'documents_processing';
        }
        if ($metrics['documents_action_required'] > 0) {
            return 'documents_need_review';
        }
        if (! $input->hasDraft) {
            return 'ready_for_generation';
        }
        $codes = array_column($blockers, 'code');
        if (array_intersect($codes, ['quality_blocked', 'no_priced_positions']) !== []) {
            return 'draft_blocked';
        }
        if (array_intersect($codes, ['norms_require_review', 'quantities_require_review', 'prices_require_review', 'quality_requires_review', 'review_items_require_action', 'review_summary_stale']) !== []) {
            return 'draft_needs_review';
        }

        return 'ready_to_apply';
    }

    /** @return array{code: string, message_key: string, message: string} */
    private function nextAction(string $status): array
    {
        return match ($status) {
            'needs_documents' => $this->issue('upload_documents', 'estimate_generation.readiness_next_upload_documents'),
            'documents_processing' => $this->issue('wait_documents', 'estimate_generation.readiness_next_wait_documents'),
            'documents_need_review' => $this->issue('review_documents', 'estimate_generation.readiness_next_review_documents'),
            'ready_for_generation' => $this->issue('generate_draft', 'estimate_generation.readiness_next_generate_draft'),
            'draft_blocked', 'draft_needs_review' => $this->issue('review_draft', 'estimate_generation.readiness_next_review_draft'),
            'ready_to_apply' => $this->issue('apply_draft', 'estimate_generation.readiness_next_apply_draft'),
            'applied' => $this->issue('open_estimate', 'estimate_generation.readiness_next_open_estimate'),
            default => $this->issue('review_session', 'estimate_generation.readiness_next_review_session'),
        };
    }

    /** @return array{code: string, message_key: string, message: string} */
    private function issue(string $code, string $messageKey): array
    {
        $message = $messageKey;
        if (function_exists('trans_message')) {
            try {
                $message = trans_message($messageKey);
            } catch (Throwable) {
            }
        }

        return ['code' => $code, 'message_key' => $messageKey, 'message' => $message];
    }
}
