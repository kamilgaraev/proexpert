<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

final readonly class AssistantOperationalReportEnricher
{
    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed>|null $ragReport
     * @return array<string, mixed>
     */
    public function enrich(array $report, ?array $ragReport): array
    {
        $report['limitations'] = array_values(is_array($report['limitations'] ?? null) ? $report['limitations'] : []);
        $report['sources'] = array_values(is_array($report['sources'] ?? null) ? $report['sources'] : []);

        if ($ragReport === null) {
            return $report;
        }

        $sources = array_values(is_array($ragReport['sources'] ?? null) ? $ragReport['sources'] : []);
        $hasStructuredData = $this->hasStructuredData($report);

        if ($sources !== []) {
            $report['rag_report'] = $ragReport;
            $report['sources'] = $sources;
        }

        if (! $hasStructuredData) {
            $report['limitations'][] = 'В структурированных разделах отчета нет записей по выбранным условиям.';
        }

        foreach ($this->limitations($ragReport) as $limitation) {
            if ($sources !== [] && ($ragReport['has_sufficient_data'] ?? false) === true) {
                continue;
            }

            $report['limitations'][] = $limitation;
        }

        $report['limitations'] = array_values(array_unique(array_filter(
            $report['limitations'],
            static fn (mixed $value): bool => is_string($value) && trim($value) !== ''
        )));

        return $report;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function hasStructuredData(array $report): bool
    {
        foreach (($report['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }

            if ((int) ($section['total'] ?? 0) > 0) {
                return true;
            }

            if (is_array($section['rows'] ?? null) && $section['rows'] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $ragReport
     * @return string[]
     */
    private function limitations(array $ragReport): array
    {
        return array_values(array_filter(
            is_array($ragReport['limitations'] ?? null) ? $ragReport['limitations'] : [],
            static fn (mixed $value): bool => is_string($value) && trim($value) !== ''
        ));
    }
}
