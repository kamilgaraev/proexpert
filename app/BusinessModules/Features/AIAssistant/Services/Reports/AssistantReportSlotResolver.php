<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantResolvedPeriod;
use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantTaskState;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantPeriodResolver;

final readonly class AssistantReportSlotResolver
{
    public function __construct(
        private AssistantPeriodResolver $periodResolver
    ) {}

    public function resolvePeriod(string|array|null $input): ?AssistantResolvedPeriod
    {
        return $this->periodResolver->resolve($input);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{id: int|string, label: string|null}|null
     */
    public function entityFromContext(array $context, string $type): ?array
    {
        $entityRefs = $context['entity_refs'] ?? null;
        if (! is_array($entityRefs)) {
            return null;
        }

        foreach ($entityRefs as $ref) {
            if (! is_array($ref) || ($ref['type'] ?? null) !== $type || ! array_key_exists('id', $ref)) {
                continue;
            }

            $id = $ref['id'];
            if (! is_int($id) && ! is_string($id)) {
                continue;
            }

            return [
                'id' => is_numeric($id) ? (int) $id : $id,
                'label' => isset($ref['label']) && is_scalar($ref['label']) ? (string) $ref['label'] : null,
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toolArguments(AssistantTaskState $state): array
    {
        $period = $state->slotValue('period');
        $periodData = is_array($period) ? $period : [];

        $arguments = [
            'period' => isset($periodData['source_text']) && is_scalar($periodData['source_text'])
                ? (string) $periodData['source_text']
                : null,
            'date_from' => isset($periodData['date_from']) && is_scalar($periodData['date_from'])
                ? (string) $periodData['date_from']
                : null,
            'date_to' => isset($periodData['date_to']) && is_scalar($periodData['date_to'])
                ? (string) $periodData['date_to']
                : null,
        ];

        if (str_starts_with($state->id, 'report.')) {
            $reportType = substr($state->id, 7);
            if ($reportType !== '' && $reportType !== 'unspecified') {
                $arguments['report_type'] = $reportType;
            }
        }

        if (in_array($state->toolName, ['generate_operational_pdf_report', 'generate_rag_pdf_report'], true)) {
            $arguments['query'] = $state->sourceMessage;
        }

        foreach (['project_id', 'warehouse_id', 'contractor_id', 'user_id'] as $slotName) {
            $value = $state->slotValue($slotName);
            if ($value !== null && $value !== '') {
                $arguments[$slotName] = $value;
            }
        }

        return array_filter($arguments, static fn (mixed $value): bool => $value !== null);
    }
}
