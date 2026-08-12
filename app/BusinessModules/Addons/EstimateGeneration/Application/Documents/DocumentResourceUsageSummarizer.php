<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class DocumentResourceUsageSummarizer
{
    /**
     * @param  list<mixed>  $payloads
     * @return array{measured_units: int, duration_ms_total: int, duration_ms_max: int, peak_memory_bytes_max: int}
     */
    public function summarize(array $payloads, array $failedUnits = []): array
    {
        $summary = [
            'measured_units' => 0,
            'duration_ms_total' => 0,
            'duration_ms_max' => 0,
            'peak_memory_bytes_max' => 0,
        ];

        foreach ($payloads as $payload) {
            $representation = is_array($payload) && is_array($payload['document_representation'] ?? null)
                ? $payload['document_representation']
                : [];
            $usage = is_array($representation['resource_usage'] ?? null)
                ? $representation['resource_usage']
                : [];
            $duration = $usage['duration_ms'] ?? null;
            $peakMemory = $usage['peak_memory_bytes'] ?? null;
            if (! is_int($duration) || $duration < 0 || ! is_int($peakMemory) || $peakMemory < 0) {
                continue;
            }

            $summary['measured_units']++;
            $summary['duration_ms_total'] += $duration;
            $summary['duration_ms_max'] = max($summary['duration_ms_max'], $duration);
            $summary['peak_memory_bytes_max'] = max($summary['peak_memory_bytes_max'], $peakMemory);
        }

        foreach ($failedUnits as $unit) {
            $metadata = is_array($unit) && is_array($unit['metadata'] ?? null) ? $unit['metadata'] : [];
            $usage = ($unit['status'] ?? null) === DocumentProcessingUnitStatus::Failed->value
                && is_array($metadata['resource_usage'] ?? null)
                ? $metadata['resource_usage']
                : [];
            $duration = $usage['duration_ms'] ?? null;
            $peakMemory = $usage['peak_memory_bytes'] ?? null;
            if (! is_int($duration) || $duration < 0 || ! is_int($peakMemory) || $peakMemory < 0) {
                continue;
            }

            $summary['measured_units']++;
            $summary['duration_ms_total'] += $duration;
            $summary['duration_ms_max'] = max($summary['duration_ms_max'], $duration);
            $summary['peak_memory_bytes_max'] = max($summary['peak_memory_bytes_max'], $peakMemory);
        }

        return $summary;
    }
}
