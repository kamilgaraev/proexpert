<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

final readonly class AssistantOperationalReportEnricher
{
    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>|null  $ragReport
     * @return array<string, mixed>
     */
    public function enrich(array $report, ?array $ragReport): array
    {
        $report['limitations'] = array_values(is_array($report['limitations'] ?? null) ? $report['limitations'] : []);
        $report['sources'] = array_values(is_array($report['sources'] ?? null) ? $report['sources'] : []);
        $report['key_findings'] = $this->stringList($report['key_findings'] ?? []);
        $report['has_structured_data'] = $this->hasStructuredData($report);

        if ($ragReport === null) {
            return $report;
        }

        $sources = array_values(is_array($ragReport['sources'] ?? null) ? $ragReport['sources'] : []);
        $hasStructuredData = (bool) $report['has_structured_data'];

        if ($sources !== []) {
            $report['rag_report'] = $ragReport;
            $report['sources'] = $sources;
            $report['rag_context_mode'] = $hasStructuredData ? 'supporting' : 'primary';
            $report['key_findings'] = $this->mergeKeyFindings(
                $report['key_findings'],
                $this->stringList($ragReport['key_findings'] ?? []),
                $hasStructuredData
            );
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
     * @param  array<string, mixed>  $report
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
     * @param  array<string, mixed>  $ragReport
     * @return string[]
     */
    private function limitations(array $ragReport): array
    {
        return array_values(array_filter(
            is_array($ragReport['limitations'] ?? null) ? $ragReport['limitations'] : [],
            static fn (mixed $value): bool => is_string($value) && trim($value) !== ''
        ));
    }

    /**
     * @return string[]
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', $values),
            static fn (string $value): bool => $value !== ''
        ));
    }

    /**
     * @param  string[]  $current
     * @param  string[]  $ragFindings
     * @return string[]
     */
    private function mergeKeyFindings(array $current, array $ragFindings, bool $hasStructuredData): array
    {
        if ($ragFindings === []) {
            return $current;
        }

        if (! $hasStructuredData) {
            return array_slice(array_values(array_unique($ragFindings)), 0, 4);
        }

        return array_slice(array_values(array_unique(array_merge($current, array_slice($ragFindings, 0, 2)))), 0, 5);
    }
}
