<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use DateInterval;
use DateTimeImmutable;

final class SupplierProposalDeliveryAssessmentService
{
    public function evaluate(?string $neededBy, ?string $deliveryDate, ?int $leadTimeDays, string $selectionDate): array
    {
        $needed = $this->date($neededBy);
        $expected = $this->date($deliveryDate);
        $selection = $this->date($selectionDate);
        if ($deliveryDate === null && $expected === null && $leadTimeDays !== null && $leadTimeDays >= 0 && $selection !== null) {
            $expected = $selection->add(new DateInterval('P'.$leadTimeDays.'D'));
        }
        $late = $needed !== null && $expected !== null ? $expected > $needed : null;

        return [
            'needed_by' => $needed?->format('Y-m-d'),
            'expected_date' => $expected?->format('Y-m-d'),
            'is_late' => $late,
            'days_late' => $late === true ? (int) $needed->diff($expected)->days : 0,
        ];
    }

    private function date(?string $value): ?DateTimeImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
