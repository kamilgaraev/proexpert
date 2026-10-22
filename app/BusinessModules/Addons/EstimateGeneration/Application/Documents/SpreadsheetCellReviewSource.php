<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

final readonly class SpreadsheetCellReviewSource
{
    public const MAX_ROWS = 8;

    public function __construct(private DocumentUnitContentReader $reader) {}

    public function capture(int $organizationId, int $projectId, int $sessionId, array $reviewedRows = []): array
    {
        $session = EstimateGenerationSession::query()->where('organization_id', $organizationId)->where('project_id', $projectId)->find($sessionId);
        if ($session === null) {
            return [];
        }
        $units = EstimateGenerationProcessingUnit::query()->with('document', 'page')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)
            ->where('unit_type', 'spreadsheet_sheet')->where('status', 'completed')->lazyById(self::MAX_ROWS);
        $rows = [];
        foreach ($units as $unit) {
            foreach ($this->unitRows($session, $unit, array_fill_keys($reviewedRows, true)) as $row) {
                $rows[] = $row;
                if (count($rows) === self::MAX_ROWS) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    public function resolve(EstimateGenerationSession $session, int $documentId, int $unitId, string $rowId): ?array
    {
        $unit = EstimateGenerationProcessingUnit::query()->with('document', 'page')
            ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)->where('session_id', $session->id)
            ->where('document_id', $documentId)->where('status', 'completed')->where('unit_type', 'spreadsheet_sheet')->find($unitId);
        if ($unit === null) {
            return null;
        }
        foreach ($this->unitRows($session, $unit, wantedRowId: $rowId) as $row) {
            if ($row['row_id'] === $rowId) {
                return $row;
            }
        }

        return null;
    }

    public static function wire(array $rows): array
    {
        return array_map(static fn (array $row): array => array_intersect_key($row, array_flip([
            'row_id', 'document_id', 'unit_id', 'source_version', 'artifact_sha256', 'sheet', 'row', 'cells', 'policy_hash',
        ])), $rows);
    }

    public static function policyHash(EstimateGenerationSession $session): string
    {
        $input = $session->input_payload ?? [];

        return hash('sha256', CanonicalPipelineJson::encode(['mode' => $input['evaluation_mode'] ?? 'legacy',
            'profile_id' => $input['profile_id'] ?? 'construction', 'selected_sections' => $input['selected_sections'] ?? [],
            'description' => is_string($input['description'] ?? null) ? trim($input['description']) : null]));
    }

    public static function proposal(mixed $proposal, array $rows): ?array
    {
        if (! is_array($proposal) || count($proposal) !== 4 || ($proposal['fact_type'] ?? null) !== 'equipment_specification'
            || ! is_string($proposal['row_id'] ?? null) || ! is_string($proposal['identity_cell_id'] ?? null)
            || ! is_string($proposal['value_cell_id'] ?? null)) {
            return null;
        }
        foreach ($rows as $row) {
            if (! is_array($row) || ! self::isWireRow(self::wire([$row])[0]) || $row['row_id'] !== $proposal['row_id']) {
                continue;
            }
            $cells = array_column($row['cells'], null, 'cell_id');
            if (isset($cells[$proposal['identity_cell_id']], $cells[$proposal['value_cell_id']])
                && $proposal['identity_cell_id'] !== $proposal['value_cell_id']) {
                return $row;
            }
        }

        return null;
    }

    public static function isWireRow(mixed $row): bool
    {
        if (! is_array($row) || count($row) !== 9
            || ! is_string($row['row_id'] ?? null) || preg_match('/\Arow:[a-f0-9]{64}\z/D', $row['row_id']) !== 1
            || ! is_int($row['document_id'] ?? null) || $row['document_id'] < 1
            || ! is_int($row['unit_id'] ?? null) || $row['unit_id'] < 1
            || ! is_string($row['source_version'] ?? null) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $row['source_version']) !== 1
            || ! is_string($row['artifact_sha256'] ?? null) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $row['artifact_sha256']) !== 1
            || ! is_string($row['policy_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $row['policy_hash']) !== 1
            || ! is_string($row['sheet'] ?? null) || trim($row['sheet']) === '' || mb_strlen($row['sheet']) > 31
            || ! is_int($row['row'] ?? null) || $row['row'] < 1 || $row['row'] > 1048576
            || ! is_array($row['cells'] ?? null) || ! array_is_list($row['cells']) || count($row['cells']) < 2 || count($row['cells']) > 16) {
            return false;
        }
        $ids = $addresses = [];
        foreach ($row['cells'] as $cell) {
            if (! is_array($cell) || count($cell) !== 3 || ! is_string($cell['cell_id'] ?? null)
                || preg_match('/\Acell:[a-f0-9]{64}\z/D', $cell['cell_id']) !== 1
                || ! is_string($cell['address'] ?? null) || preg_match('/\A[A-Z]{1,3}'. $row['row'].'\z/D', $cell['address']) !== 1
                || ! is_string($cell['raw_value'] ?? null) || trim($cell['raw_value']) === '' || mb_strlen($cell['raw_value']) > 500
                || isset($ids[$cell['cell_id']]) || isset($addresses[$cell['address']])) {
                return false;
            }
            $ids[$cell['cell_id']] = $addresses[$cell['address']] = true;
        }

        return true;
    }

    private function unitRows(EstimateGenerationSession $session, EstimateGenerationProcessingUnit $unit, array $reviewedRows = [], ?string $wantedRowId = null): array
    {
        $document = $unit->document;
        $page = $unit->page;
        if (! $document instanceof EstimateGenerationDocument || $document->ignored_at !== null
            || $document->processing_control_status === 'ignored' || $document->source_version !== $unit->source_version
            || [(int) $document->organization_id, (int) $document->project_id, (int) $document->session_id]
                !== [(int) $session->organization_id, (int) $session->project_id, (int) $session->id]
            || $page === null || $page->excluded_at !== null || $page->source_version !== $unit->source_version
            || (int) $page->document_id !== (int) $document->id || (int) $page->page_number !== $unit->unit_index) {
            return [];
        }
        if (($page->normalized_payload['native_understanding']['extracted_capabilities']['has_quantities'] ?? false) === true
            || $page->facts()->where('normalized_payload->fact_type', 'equipment_specification')
                ->where(static fn ($query) => $query->whereNull('normalized_payload->decision_reason_code')
                    ->orWhere('normalized_payload->decision_reason_code', '!=', 'human_reviewed_literal'))->exists()) {
            return [];
        }
        $context = new DocumentUnitExecutionContext((int) $unit->id, (int) $session->organization_id, (int) $session->project_id,
            (int) $session->id, (int) $document->id, DocumentUnitType::SpreadsheetSheet, $unit->unit_index, $unit->source_version,
            $unit->locator, $document->storage_path, $document->mime_type, $document->filename, '', $unit->attempt_count,
            (int) $session->state_version, $session->status->value, (int) $page->id);
        if (($context->locator['artifact_kind'] ?? null) !== 'spreadsheet_sheet'
            || ($context->locator['artifact_schema_version'] ?? null) !== 1) {
            return [];
        }
        OriginalDocumentArtifactLocator::forUnit($document, DocumentUnitType::SpreadsheetSheet, $unit->unit_index, $unit->source_version, []);
        $bytes = $this->reader->read($context);
        $literal = NativeSpreadsheetLiteralSources::fromArtifact($context, $bytes);
        if ($literal === null) {
            return [];
        }
        $payload = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $native = $payload['native_structure'];
        if (($native['merged_cells'] ?? $native['merged_ranges'] ?? []) !== []) {
            return [];
        }
        $grouped = $addresses = [];
        foreach ($native['cells'] ?? [] as $cell) {
            $address = $cell['address'] ?? null;
            if (! is_array($cell) || ! is_string($address) || preg_match('/\A[A-Z]{1,3}([1-9][0-9]*)\z/D', $address, $match) !== 1) {
                return [];
            }
            if (isset($addresses[$address])) {
                return [];
            }
            $addresses[$address] = true;
            if (($cell['formula'] ?? null) !== null || ! is_string($cell['raw_value'] ?? null)
                || $cell['raw_value'] !== ($cell['value'] ?? null) || trim($cell['raw_value']) === ''
                || mb_strlen($cell['raw_value']) > 500) {
                continue;
            }
            $grouped[(int) $match[1]][] = $cell;
        }
        ksort($grouped, SORT_NUMERIC);
        $rows = [];
        foreach ($grouped as $index => $cells) {
            if (count($cells) < 2 || count($cells) > 16) {
                continue;
            }
            $binding = [(int) $document->id, (int) $unit->id, $unit->source_version, $unit->locator['artifact_sha256'], $index];
            $rowId = 'row:'.hash('sha256', CanonicalPipelineJson::encode($binding));
            if (isset($reviewedRows[$rowId]) || ($wantedRowId !== null && $wantedRowId !== $rowId)) {
                continue;
            }
            $rows[] = ['row_id' => $rowId, 'document_id' => (int) $document->id, 'unit_id' => (int) $unit->id,
                'source_version' => $unit->source_version, 'artifact_sha256' => $unit->locator['artifact_sha256'],
                'sheet' => $native['sheet'], 'row' => $index,
                'policy_hash' => self::policyHash($session),
                'cells' => array_map(static fn (array $cell): array => ['cell_id' => 'cell:'.hash('sha256', CanonicalPipelineJson::encode([$rowId, $cell['address'], $cell['raw_value']])),
                    'address' => $cell['address'], 'raw_value' => $cell['raw_value']], $cells),
                'context' => $context, 'native' => $native, 'literal_sources' => $literal];
            if (count($rows) === self::MAX_ROWS) {
                break;
            }
        }

        return $rows;
    }
}
