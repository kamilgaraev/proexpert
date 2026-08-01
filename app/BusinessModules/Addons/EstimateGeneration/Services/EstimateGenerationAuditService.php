<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationAuditEvent;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSheetAnalysisOperation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class EstimateGenerationAuditService
{
    public const EVENT_NORMATIVE_DECISION_SUMMARY = 'normative_decision_summary';

    public const EVENT_SHEET_TARGETED_REANALYSIS = 'sheet_targeted_reanalysis';

    /** @param array<string, mixed> $routing */
    public function recordSheetTargetedReanalysis(EstimateGenerationSession $session, int $documentId, int $unitId, string $sourceVersion, array $routing, string $operationId, string $outcome): void
    {
        $payload = [
            'operation_id' => $operationId,
            'document_id' => $documentId,
            'unit_id' => $unitId,
            'source_version' => $sourceVersion,
            'reason' => $routing['reanalysis_reason'] ?? null,
            'target_role' => $routing['role'] ?? null,
            'routing_version' => 'sheet-routing:v1',
            'outcome' => $outcome,
        ];
        DB::transaction(function () use ($session, $operationId, $payload): void {
            $operation = EstimateGenerationSheetAnalysisOperation::query()->lockForUpdate()->find($operationId);
            if (! $operation instanceof EstimateGenerationSheetAnalysisOperation || $operation->audit_recorded_at !== null) {
                return;
            }
            try {
                EstimateGenerationAuditEvent::query()->create([
                    'session_id' => $session->id, 'package_id' => null, 'user_id' => $session->user_id,
                    'event_type' => self::EVENT_SHEET_TARGETED_REANALYSIS, 'payload' => $payload,
                ]);
            } catch (QueryException $exception) {
                if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) !== '23505') {
                    throw $exception;
                }
            }
            $operation->forceFill(['audit_recorded_at' => now()])->save();
        }, 3);
    }

    /**
     * @param array<string, mixed> $draft
     */
    public function recordNormativeDecisionSummary(EstimateGenerationSession $session, array $draft): void
    {
        $packagesByKey = $session->packages()->get()->keyBy('key');

        foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
            if (!is_array($localEstimate)) {
                continue;
            }

            $packageKey = (string) ($localEstimate['key'] ?? '');
            $package = $packagesByKey->get($packageKey);

            EstimateGenerationAuditEvent::query()->create([
                'session_id' => $session->id,
                'package_id' => $package instanceof EstimateGenerationPackage ? $package->id : null,
                'user_id' => $session->user_id,
                'event_type' => self::EVENT_NORMATIVE_DECISION_SUMMARY,
                'payload' => $this->payload($localEstimate),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $localEstimate
     * @return array<string, mixed>
     */
    private function payload(array $localEstimate): array
    {
        $workItems = $this->workItems($localEstimate);
        $counters = [
            'accepted' => 0,
            'review_priced' => 0,
            'candidate_only' => 0,
            'not_found' => 0,
            'unit_mismatch' => 0,
            'scope_mismatch' => 0,
            'safe_norm_required' => 0,
        ];
        $maxLineTotal = 0.0;

        foreach ($workItems as $workItem) {
            $flags = $this->normativeFlags($workItem);
            $matchStatus = (string) data_get($workItem, 'normative_match.status', '');
            $decisionStatus = (string) data_get($workItem, 'normative_match.decision.status', '');
            $canUseForPricing = data_get($workItem, 'normative_match.decision.can_use_for_pricing');
            $lineTotal = (float) ($workItem['total_cost'] ?? 0);
            $maxLineTotal = max($maxLineTotal, $lineTotal);

            if (in_array('unit_mismatch', $flags, true)) {
                $counters['unit_mismatch']++;
            }

            if (in_array('scope_mismatch', $flags, true)) {
                $counters['scope_mismatch']++;
            }

            if (in_array('safe_norm_required', $flags, true) || in_array('pricing_not_calculated', $flags, true)) {
                $counters['safe_norm_required']++;
            }

            if (in_array($matchStatus, ['not_found', 'unmatched'], true) || in_array('normative_not_found', $flags, true)) {
                $counters['not_found']++;
                continue;
            }

            if ($canUseForPricing === true || ($matchStatus === 'matched' && $lineTotal > 0)) {
                if ($this->requiresReview($decisionStatus, $flags)) {
                    $counters['review_priced']++;
                    continue;
                }

                $counters['accepted']++;
                continue;
            }

            if (
                in_array($matchStatus, ['candidate', 'low_confidence'], true)
                || in_array('candidate_only', $flags, true)
                || in_array('normative_candidate_only', $flags, true)
            ) {
                $counters['candidate_only']++;
            }
        }

        return [
            'local_estimate_key' => (string) ($localEstimate['key'] ?? ''),
            'title' => (string) ($localEstimate['title'] ?? ''),
            'scope_type' => (string) ($localEstimate['scope_type'] ?? 'custom'),
            'items_count' => count($workItems),
            ...$counters,
            'max_line_total' => round($maxLineTotal, 2),
        ];
    }

    /**
     * @param array<string, mixed> $localEstimate
     * @return array<int, array<string, mixed>>
     */
    private function workItems(array $localEstimate): array
    {
        $items = [];

        foreach ($localEstimate['sections'] ?? [] as $section) {
            if (!is_array($section)) {
                continue;
            }

            foreach ($section['work_items'] ?? [] as $workItem) {
                if (is_array($workItem)) {
                    $items[] = $workItem;
                }
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $workItem
     * @return array<int, string>
     */
    private function normativeFlags(array $workItem): array
    {
        return array_values(array_unique([
            ...$this->stringList($workItem['validation_flags'] ?? []),
            ...$this->stringList(data_get($workItem, 'normative_match.warnings', [])),
            ...$this->stringList(data_get($workItem, 'normative_match.decision.warnings', [])),
        ]));
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $value),
            static fn (string $item): bool => $item !== ''
        ));
    }

    /**
     * @param array<int, string> $flags
     */
    private function requiresReview(string $decisionStatus, array $flags): bool
    {
        return $decisionStatus === 'review_priced'
            || in_array('review_priced', $flags, true)
            || in_array('requires_normative_review', $flags, true)
            || in_array('low_confidence', $flags, true)
            || in_array('low_normative_confidence', $flags, true);
    }
}
