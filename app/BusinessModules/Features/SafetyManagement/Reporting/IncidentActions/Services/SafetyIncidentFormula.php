<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Services;

use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\DTO\SafetyTransitionFact;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyIncidentPolicyVersion;
use InvalidArgumentException;

final readonly class SafetyIncidentFormula
{
    public function frequency(int $qualifyingIncidentCount, ?string $exposureHours, int $multiplier): ?string
    {
        if ($qualifyingIncidentCount < 0 || $multiplier < 1) {
            throw new InvalidArgumentException('safety_incident_frequency_input_invalid');
        }

        if ($exposureHours === null) {
            return null;
        }

        $scaledHours = $this->decimalToScaledInteger($exposureHours);
        if ($scaledHours === 0) {
            return null;
        }

        $numerator = $qualifyingIncidentCount * $multiplier * 10_000;
        $scaled = intdiv($numerator * 10_000 + intdiv($scaledHours, 2), $scaledHours);

        return sprintf('%d.%04d', intdiv($scaled, 10_000), $scaled % 10_000);
    }

    public function isClosure(SafetyTransitionFact $fact, SafetyIncidentPolicyVersion $policy): bool
    {
        if (! in_array($fact->toStatus, $this->terminalStatuses($policy, $fact->subjectType), true)) {
            return false;
        }

        if ($fact->subjectType === 'corrective_action'
            && ($fact->resolvedAt === null || $fact->verifiedAt === null)) {
            return false;
        }

        return ! (bool) $policy->closure_evidence_required
            || ($fact->evidenceId !== null && trim($fact->evidenceId) !== '');
    }

    public function actionClosure(SafetyTransitionFact $fact, SafetyIncidentPolicyVersion $policy): bool
    {
        return $fact->subjectType === 'corrective_action' && $this->isClosure($fact, $policy);
    }

    public function isReopen(SafetyTransitionFact $fact, SafetyIncidentPolicyVersion $policy): bool
    {
        $terminalStatuses = $this->terminalStatuses($policy, $fact->subjectType);

        return $fact->fromStatus !== null
            && in_array($fact->fromStatus, $terminalStatuses, true)
            && ! in_array($fact->toStatus, $terminalStatuses, true);
    }

    private function decimalToScaledInteger(string $value): int
    {
        if (preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,4}))?$/D', $value, $matches) !== 1) {
            throw new InvalidArgumentException('safety_incident_exposure_invalid');
        }

        $fraction = str_pad($matches[2] ?? '', 4, '0');

        return ((int) $matches[1] * 10_000) + (int) $fraction;
    }

    private function terminalStatuses(SafetyIncidentPolicyVersion $policy, string $subjectType): array
    {
        $statusesByType = $policy->terminal_statuses;
        $statuses = is_array($statusesByType) ? ($statusesByType[$subjectType] ?? null) : null;
        if (! is_array($statuses) || $statuses === [] || array_filter($statuses, 'is_string') !== $statuses) {
            throw new InvalidArgumentException('safety_incident_terminal_statuses_invalid');
        }

        return array_values(array_unique($statuses));
    }
}
