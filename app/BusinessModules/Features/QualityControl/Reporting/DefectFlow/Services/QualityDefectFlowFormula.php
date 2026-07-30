<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO\DefectTransitionTimeline;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectFlowPolicyVersion;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectTransitionEvent;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class QualityDefectFlowFormula
{
    public function rollForward(int $opening, int $created, int $reopened, int $closed): int
    {
        if (min($opening, $created, $reopened, $closed) < 0) {
            throw new InvalidArgumentException('quality_defect_flow_count_invalid');
        }

        $closing = $opening + $created + $reopened - $closed;
        if ($closing < 0) {
            throw new InvalidArgumentException('quality_defect_flow_reconciliation_invalid');
        }

        return $closing;
    }

    public function percentage(int $numerator, int $denominator): ?string
    {
        if ($numerator < 0 || $denominator < 0 || $numerator > $denominator) {
            throw new InvalidArgumentException('quality_defect_flow_percentage_invalid');
        }
        if ($denominator === 0) {
            return null;
        }
        $scaled = intdiv($numerator * 1_000_000 + intdiv($denominator, 2), $denominator);

        return sprintf('%d.%04d', intdiv($scaled, 10_000), $scaled % 10_000);
    }

    public function isReopen(
        QualityDefectTransitionEvent|DefectTransitionTimeline $event,
        QualityDefectFlowPolicyVersion $policy,
    ): bool {
        $terminalStatuses = $this->terminalStatuses($policy);

        return in_array($event->fromStatus ?? $event->from_status, $terminalStatuses, true)
            && ! in_array($event->toStatus ?? $event->to_status, $terminalStatuses, true);
    }

    public function isClosure(
        QualityDefectTransitionEvent|DefectTransitionTimeline $event,
        QualityDefectFlowPolicyVersion $policy,
    ): bool {
        $toStatus = $event->toStatus ?? $event->to_status;
        $evidencePresent = $event instanceof DefectTransitionTimeline
            ? $event->closureEvidencePresent
            : $this->hasVerifiedEvidence($event->evidence_refs ?? []);

        return in_array($toStatus, $this->terminalStatuses($policy), true)
            && (! (bool) $policy->closure_evidence_required || $evidencePresent);
    }

    private function hasVerifiedEvidence(array $references): bool
    {
        foreach ($references as $reference) {
            if (is_array($reference)
                && ($reference['coverage'] ?? 'verified') === 'verified') {
                return true;
            }
        }

        return false;
    }

    public function matureCohort(
        iterable $timelines,
        QualityDefectFlowPolicyVersion $policy,
        ?DateTimeImmutable $asOf = null,
    ): ReportCoverage {
        $asOf ??= new DateTimeImmutable;
        $eligible = [];
        foreach ($timelines as $timeline) {
            if (! $timeline instanceof DefectTransitionTimeline) {
                throw new InvalidArgumentException('quality_defect_flow_timeline_invalid');
            }

            if ($timeline->cohortAt->modify(sprintf('+%d days', (int) $policy->maturity_days)) > $asOf) {
                continue;
            }

            $eligible[$timeline->defectId] ??= [
                'resolved' => false,
                'reopened' => false,
            ];
            $eligible[$timeline->defectId]['resolved'] = $eligible[$timeline->defectId]['resolved']
                || (
                    $timeline->resolvedAt !== null
                    && in_array($timeline->toStatus, $this->terminalStatuses($policy), true)
                    && (
                        ! (bool) $policy->closure_evidence_required
                        || $timeline->closureEvidencePresent
                    )
                );
            $eligible[$timeline->defectId]['reopened'] = $eligible[$timeline->defectId]['reopened']
                || $this->isReopen($timeline, $policy);
        }

        $numerator = 0;
        foreach ($eligible as $state) {
            if ($state['resolved'] && ! $state['reopened']) {
                $numerator++;
            }
        }

        $denominator = count($eligible);

        return new ReportCoverage(
            (string) $numerator,
            (string) $denominator,
            $denominator === 0 ? null : $this->ratio($numerator, $denominator),
        );
    }

    private function ratio(int $numerator, int $denominator): string
    {
        $scaled = intdiv($numerator * 10_000 + intdiv($denominator, 2), $denominator);

        return sprintf('%d.%04d', intdiv($scaled, 10_000), $scaled % 10_000);
    }

    private function terminalStatuses(QualityDefectFlowPolicyVersion $policy): array
    {
        $statuses = $policy->terminal_statuses;
        if (! is_array($statuses) || $statuses === [] || array_filter($statuses, 'is_string') !== $statuses) {
            throw new InvalidArgumentException('quality_defect_flow_terminal_statuses_invalid');
        }

        return array_values(array_unique($statuses));
    }
}
