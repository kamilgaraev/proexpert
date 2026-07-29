<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services;

use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO\LookaheadConstraintState;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO\LookaheadEligibilityInput;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO\LookaheadReadinessCoverage;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO\LookaheadReadinessMetric;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessPolicyVersion;
use DateInterval;
use InvalidArgumentException;

final readonly class LookaheadReadinessFormula
{
    public function evaluate(
        LookaheadEligibilityInput $input,
        LookaheadReadinessPolicyVersion $policy,
    ): LookaheadReadinessMetric {
        if (!$policy->appliesAt($input->asOf)) {
            throw new InvalidArgumentException('lookahead_policy_not_effective');
        }

        $horizonEnd = $input->asOf->add(new DateInterval('P'.$policy->horizonDays.'D'));
        $eligible = !$input->container
            && in_array($input->status, $policy->eligibleTaskStatuses, true)
            && $input->plannedStart >= $input->asOf
            && $input->plannedStart <= $horizonEnd;

        if (!$eligible) {
            return new LookaheadReadinessMetric($input->taskId, false, false, [], 0, 0, null, 0);
        }

        $blockingIds = [];
        $hard = 0;
        $soft = 0;
        $warning = null;
        $maxAge = 0;

        foreach ($input->constraints as $constraint) {
            if (!$this->isMandatory($constraint, $policy)) {
                continue;
            }

            $released = in_array($constraint->status, ['resolved', 'closed', 'completed'], true);
            if ($constraint->status === 'waived') {
                $waiverActive = $constraint->waiverUntil !== null
                    && $constraint->waiverUntil >= $input->asOf
                    && (!$policy->waiverEvidenceRequired || trim((string) $constraint->waiverEvidenceRef) !== '');
                $released = $waiverActive;
                if (!$waiverActive) {
                    $warning = $constraint->waiverUntil !== null && $constraint->waiverUntil < $input->asOf
                        ? 'LOOKAHEAD_WAIVER_EXPIRED'
                        : 'LOOKAHEAD_WAIVER_EVIDENCE_MISSING';
                }
            }

            if ($released) {
                if ($this->requiresLinkedEvidence($constraint) && $constraint->linkedResourceId === null) {
                    $warning = 'LOOKAHEAD_LINKED_EVIDENCE_MISSING';
                }
                continue;
            }

            $blockingIds[] = $constraint->constraintId;
            if (in_array($constraint->severity, $policy->hardSeverities, true)) {
                $hard++;
            } else {
                $soft++;
            }

            if ($constraint->openedAt !== null && $constraint->openedAt < $input->asOf) {
                $maxAge = max($maxAge, (int) $constraint->openedAt->diff($input->asOf)->days);
            }
        }

        sort($blockingIds, SORT_NUMERIC);

        return new LookaheadReadinessMetric(
            $input->taskId,
            true,
            $blockingIds === [],
            $blockingIds,
            $hard,
            $soft,
            $warning,
            $maxAge,
        );
    }

    public function summarize(iterable $taskMetrics): LookaheadReadinessCoverage
    {
        $eligible = 0;
        $ready = 0;
        $hard = 0;
        $soft = 0;
        $taskIds = [];

        foreach ($taskMetrics as $metric) {
            if (!$metric instanceof LookaheadReadinessMetric) {
                throw new InvalidArgumentException('lookahead_summary_metric_invalid');
            }
            if (isset($taskIds[$metric->taskId])) {
                throw new InvalidArgumentException('lookahead_summary_task_duplicate');
            }
            $taskIds[$metric->taskId] = true;
            if (!$metric->eligible) {
                continue;
            }

            $eligible++;
            $ready += $metric->ready ? 1 : 0;
            $hard += $metric->hardBlockers;
            $soft += $metric->softBlockers;
        }

        return new LookaheadReadinessCoverage(
            (string) $ready,
            (string) $eligible,
            $eligible === 0 ? null : $this->ratio($ready, $eligible),
            $hard,
            $soft,
        );
    }

    private function isMandatory(
        LookaheadConstraintState $constraint,
        LookaheadReadinessPolicyVersion $policy,
    ): bool {
        return in_array($constraint->type, $policy->mandatoryConstraintTypes, true);
    }

    private function requiresLinkedEvidence(LookaheadConstraintState $constraint): bool
    {
        $type = strtolower($constraint->type);

        return str_contains($type, 'rfi') || str_contains($type, 'procurement');
    }

    private function ratio(int $numerator, int $denominator): string
    {
        $scaled = intdiv($numerator * 100_000_000, $denominator);

        return intdiv($scaled, 100_000_000)
            .'.'
            .str_pad((string) ($scaled % 100_000_000), 8, '0', STR_PAD_LEFT);
    }
}
