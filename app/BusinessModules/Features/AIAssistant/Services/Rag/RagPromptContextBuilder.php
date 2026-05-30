<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagSearchResult;
use DateTimeInterface;
use Throwable;

final class RagPromptContextBuilder
{
    /**
     * @param  array<int, RagSearchResult>  $results
     * @return array{prompt: string, metadata: array<string, mixed>}
     */
    public function build(string $query, array $results): array
    {
        $requested = $this->configInt('ai-assistant.rag.max_chunks', 8);
        $results = array_slice($results, 0, $requested);
        $sources = $this->sources($results);
        $used = $sources !== [];

        return [
            'prompt' => $used ? $this->prompt($sources) : '',
            'metadata' => [
                'enabled' => true,
                'used' => $used,
                'query' => $query,
                'sources' => $used ? $sources : [],
                'limits' => [
                    'requested' => $requested,
                    'returned' => $used ? count($sources) : 0,
                ],
            ],
        ];
    }

    /**
     * @param  array<int, RagSearchResult>  $results
     * @return array<int, array<string, mixed>>
     */
    private function sources(array $results): array
    {
        return array_values(array_map(static fn (RagSearchResult $result): array => [
            'source_type' => $result->sourceType,
            'entity_type' => $result->entityType,
            'entity_id' => $result->entityId,
            'project_id' => $result->projectId,
            'title' => $result->title,
            'excerpt' => $result->excerpt,
            'score' => round($result->similarity, 4),
            'updated_at' => $result->updatedAt?->format(DateTimeInterface::ATOM),
            'navigation_target' => self::navigationTarget($result),
        ], $results));
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     */
    private function prompt(array $sources): string
    {
        $lines = [
            'ProHelper context:',
            'Answer guidance:',
            '- Отвечай на русском и опирайся только на источники ниже.',
            '- Если запрос о проблемах, рисках, заявках или внимании, дай компактный рабочий список в формате: Проблема — что не так — что сделать.',
            '- Если пользователь просит сравнить договоры, сметы, работы и платежи, дай текстовый анализ расхождений и рисков по найденным источникам, без генерации файла.',
            '- Если запрос только или явно именно про справочники, нормативы, каталоги или расценки, не подменяй их проектными сметами; если вопрос одновременно про сметы и нормативы, используй оба типа источников и отдельно отметь, если справочников нет.',
            '- Не называй проект или ситуацию критической без явной метки critical/urgent/high или прямого критического факта в источниках; при косвенных признаках пиши мягче: "есть признаки проблемы".',
        ];

        foreach ($sources as $index => $source) {
            $lines[] = sprintf(
                '[%d] %s: %s',
                $index + 1,
                (string) ($source['title'] ?? ''),
                (string) ($source['excerpt'] ?? '')
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{route: string, anchor?: string, state?: array<string, mixed>}|null
     */
    private static function navigationTarget(RagSearchResult $result): ?array
    {
        $entityId = is_numeric($result->entityId) ? (int) $result->entityId : null;
        $journalId = self::metadataInt($result, 'journal_id');
        $documentSetId = self::metadataInt($result, 'document_set_id');
        $estimateId = self::metadataInt($result, 'estimate_id');

        $route = match ($result->entityType) {
            'project' => $entityId !== null ? "/projects/{$entityId}" : null,
            'schedule' => $entityId !== null && $result->projectId !== null
                ? "/projects/{$result->projectId}/schedules/{$entityId}"
                : ($result->projectId !== null ? "/projects/{$result->projectId}/schedules" : '/schedules'),
            'contract' => $entityId !== null ? "/contracts/{$entityId}" : null,
            'estimate' => $entityId !== null && $result->projectId !== null
                ? "/projects/{$result->projectId}/estimates/{$entityId}"
                : ($result->projectId !== null ? "/projects/{$result->projectId}/estimates" : null),
            'estimate_section' => $estimateId !== null && $result->projectId !== null
                ? "/projects/{$result->projectId}/estimates/{$estimateId}"
                : ($result->projectId !== null ? "/projects/{$result->projectId}/estimates" : null),
            'estimate_template' => '/templates/library',
            'estimate_library_item' => '/libraries',
            'normative_rate', 'estimate_catalog_item' => '/catalogs/estimate-positions',
            'purchase_request' => $entityId !== null ? "/procurement/purchase-requests/{$entityId}" : null,
            'site_request' => $entityId !== null ? "/site-requests/{$entityId}" : null,
            'completed_work' => $entityId !== null ? "/completed-works/{$entityId}" : null,
            'construction_journal_entry' => $entityId !== null && $journalId !== null
                ? "/journals/{$journalId}/entries/{$entityId}"
                : ($result->projectId !== null ? "/projects/{$result->projectId}/journals" : null),
            'performance_act' => $entityId !== null ? "/acts/{$entityId}" : '/acts',
            'payment_document' => $entityId !== null ? "/payments/documents/{$entityId}" : '/payments/documents',
            'quality_defect' => $entityId !== null ? "/quality-control/defects/{$entityId}" : '/quality-control/defects',
            'executive_document_set' => $entityId !== null ? "/executive-documentation/sets/{$entityId}" : '/executive-documentation/sets',
            'executive_document' => $documentSetId !== null ? "/executive-documentation/sets/{$documentSetId}" : '/executive-documentation/sets',
            'project_pulse_report' => $entityId !== null ? "/project-pulse/reports/{$entityId}" : '/project-pulse',
            'project_material_delivery' => '/warehouse',
            'warehouse_balance', 'warehouse_movement', 'warehouse_project_allocation', 'asset_reservation',
            'inventory_act', 'warehouse_storage_cell', 'warehouse_task', 'warehouse_asset' => '/warehouse',
            'schedule_task' => $result->projectId !== null ? "/projects/{$result->projectId}/schedules" : '/schedules',
            'supplier_request', 'supplier_proposal', 'purchase_order', 'purchase_receipt',
            'procurement_approval', 'procurement_audit_event', 'supplier_proposal_decision' => '/procurement',
            'safety_incident', 'safety_violation', 'safety_work_permit', 'safety_briefing',
            'safety_corrective_action' => '/safety',
            'machinery_asset', 'machinery_assignment', 'machinery_downtime', 'machinery_fuel_issue',
            'machinery_maintenance_order', 'machinery_production_record', 'machinery_shift_report' => '/machinery',
            'production_labor_work_order', 'production_labor_work_order_line', 'production_labor_timesheet',
            'production_labor_timesheet_entry', 'production_labor_output_entry',
            'production_labor_payroll_accrual' => '/production-labor',
            'change_request', 'change_claim', 'change_impact', 'change_management_rfi',
            'change_approval', 'variation_order' => '/change-management',
            'handover_package', 'handover_package_document', 'acceptance_session', 'acceptance_scope',
            'acceptance_checklist', 'acceptance_checklist_item', 'acceptance_finding',
            'acceptance_signoff', 'project_location' => '/handover-acceptance',
            default => $result->projectId !== null ? "/projects/{$result->projectId}" : null,
        };

        if (! is_string($route) || trim($route) === '') {
            return null;
        }

        return [
            'route' => $route,
            'state' => [
                'assistant_source' => [
                    'title' => $result->title,
                    'source_type' => $result->sourceType,
                    'entity_type' => $result->entityType,
                    'entity_id' => $result->entityId,
                ],
            ],
        ];
    }

    private static function metadataInt(RagSearchResult $result, string $key): ?int
    {
        $value = $result->metadata[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function configInt(string $key, int $default): int
    {
        try {
            $value = config($key, $default);
        } catch (Throwable) {
            return $default;
        }

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
