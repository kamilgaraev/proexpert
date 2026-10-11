<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagSearchResult;
use App\BusinessModules\Features\AIAssistant\Services\AssistantToolResultProjection;
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
            'fetched_at' => now()->toISOString(),
            'source_id' => $result->metadata['source_id'] ?? null,
            'source_version' => $result->metadata['source_version'] ?? null,
            'checksum' => $result->metadata['checksum'] ?? null,
            'assistant_public_schema_revision' => $result->metadata['assistant_public_schema_revision'] ?? null,
            ...self::referenceProof($result->metadata),
            ...self::documentEvidence($result->metadata),
            'content_scope' => 'unstructured',
            'navigation_target' => self::navigationTarget($result),
            'navigation' => self::navigationTarget($result) !== null ? ['url' => self::navigationTarget($result)['route']] : null,
        ], $results));
    }

    public static function documentEvidence(array $metadata): array
    {
        $evidence = [];
        foreach (['document_id', 'file_id', 'unit_id', 'unit_index', 'text_offset', 'text_length'] as $key) {
            if (is_int($metadata[$key] ?? null) && $metadata[$key] >= 0) { $evidence[$key] = $metadata[$key]; }
        }
        foreach (['unit_checksum', 'unit_type', 'coverage_status', 'document_checksum', 'rag_source_checksum'] as $key) {
            if (is_string($metadata[$key] ?? null) && mb_strlen($metadata[$key]) <= 128) { $evidence[$key] = $metadata[$key]; }
        }
        if (is_bool($metadata['document_truncated'] ?? null)) { $evidence['document_truncated'] = $metadata['document_truncated']; }
        if (is_array($metadata['provenance'] ?? null)) {
            $provenance = [];
            foreach (['page', 'row', 'row_start', 'row_end', 'paragraph'] as $key) {
                if (is_int($metadata['provenance'][$key] ?? null) && $metadata['provenance'][$key] >= 0) { $provenance[$key] = $metadata['provenance'][$key]; }
            }
            foreach (['sheet', 'kind', 'path', 'part', 'section', 'column'] as $key) {
                if (is_string($metadata['provenance'][$key] ?? null) && mb_strlen($metadata['provenance'][$key]) <= 256) { $provenance[$key] = $metadata['provenance'][$key]; }
            }
            $cells = $metadata['provenance']['cells'] ?? null;
            if (is_array($cells) && array_is_list($cells) && count($cells) <= 64
                && count(array_filter($cells, static fn (mixed $cell): bool => is_string($cell) && preg_match('/^[A-Z]{1,3}$/D', $cell) === 1)) === count($cells)) {
                $provenance['cells'] = $cells;
            }
            $evidence['provenance'] = $provenance;
        }
        return $evidence;
    }

    private static function referenceProof(array $metadata): array
    {
        $proof = [];
        if (is_string($metadata['source_field_identity'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $metadata['source_field_identity']) === 1) {
            $proof['source_field_identity'] = $metadata['source_field_identity'];
        }
        foreach (['checked_fields', 'required_permissions', 'required_domains'] as $key) {
            $values = $metadata[$key] ?? null;
            if (is_array($values) && array_is_list($values) && count($values) <= 64
                && count(array_filter($values, static fn (mixed $value): bool => is_string($value) && strlen($value) <= 128
                    && preg_match('/^[a-zA-Z_][a-zA-Z0-9_.:-]*$/D', $value) === 1)) === count($values)) {
                $proof[$key] = $values;
            }
        }
        return $proof;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     */
    private function prompt(array $sources): string
    {
        $public = AssistantToolResultProjection::forProvider('rag_search', ['rag_context' => ['sources' => $sources]]);
        return json_encode(['kind' => 'untrusted_search_results', 'sources' => $public['rag_context']['sources']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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
            'estimate' => $entityId !== null ? "/estimates/{$entityId}" : null,
            'estimate_section', 'estimate_item', 'estimate_resource', 'estimate_item_resource' => $estimateId !== null
                ? "/estimates/{$estimateId}"
                : ($result->projectId !== null ? "/projects/{$result->projectId}/estimates" : null),
            'estimate_template' => '/templates/library',
            'estimate_library_item' => '/libraries',
            'normative_rate', 'estimate_catalog_item' => '/catalogs/estimate-positions',
            'purchase_request' => $entityId !== null ? "/procurement/purchase-requests/{$entityId}" : null,
            'site_request' => $entityId !== null ? "/site-requests/{$entityId}" : null,
            'completed_work' => $entityId !== null ? '/workflow/completed-works' : null,
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
