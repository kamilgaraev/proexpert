<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\Models\ImportSession;
use Illuminate\Support\Facades\Log;

class StagingAreaService
{
    public function __construct(
        private readonly EstimateImportService $importService,
        private readonly FormulaAwarenessService $formulaAwareness,
        private readonly AnomalyDetectionService $anomalyDetector,
        private readonly SubItemGroupingService $subItemGrouper,
    ) {}

    public function buildPreview(string $sessionId, int $organizationId): array
    {
        $session = ImportSession::findOrFail($sessionId);

        $structure     = $session->options['structure'] ?? [];
        $columnMapping = $structure['column_mapping'] ?? [];

        if (empty($columnMapping)) {
            return [
                'rows'    => [],
                'stats'   => ['total' => 0, 'sections' => 0, 'items' => 0, 'anomalies' => 0, 'mismatches' => 0],
                'warning' => 'Маппинг колонок не задан. Выполните шаг detect сначала.',
            ];
        }

        $fullPath = app(FileStorageService::class)->getAbsolutePath($session);

        $parser  = app(\App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\Factory\ParserFactory::class)->getParser($fullPath);
        $options = [
            'header_row'     => $structure['header_row'] ?? null,
            'column_mapping' => $columnMapping,
        ];

        $mapper = app(ImportRowMapper::class);

        if (!empty($structure['ai_section_hints'])) {
            $mapper->setSectionHints($structure['ai_section_hints']);
        }

        if (!empty($structure['row_styles'])) {
            $mapper->setRowStyles($structure['row_styles']);
        }

        $rows    = [];
        $stream  = $parser->getStream($fullPath, $options);

        foreach ($stream as $dto) {
            if ($dto->isFooter) {
                continue;
            }
            
            // 🔧 ИСПРАВЛЕНИЕ: Прогоняем через маппер, чтобы заполнить поля из rawData
            $dto = $mapper->map($dto, $columnMapping);
            
            $rows[] = $dto->toArray();
        }

        $grouped = $this->subItemGrouper->groupItems($rows);

        $this->formulaAwareness->annotate($grouped);

        $this->anomalyDetector->annotateFromImport($grouped, $organizationId);

        $stats = $this->buildStats($grouped);

        Log::info("[StagingArea] Preview built for session {$sessionId}", $stats);

        return [
            'session_id' => $sessionId,
            'rows'       => $grouped,
            'stats'      => $stats,
        ];
    }

    private function buildStats(array $rows): array
    {
        $sections   = 0;
        $items      = 0;
        $anomalies  = 0;
        $mismatches = 0;

        foreach ($rows as $row) {
            if ($row['is_section'] ?? false) {
                $sections++;
            } else {
                $items++;
                if (!empty($row['anomaly']['is_anomaly'])) {
                    $anomalies++;
                }
                if (!empty($row['has_math_mismatch'])) {
                    $mismatches++;
                }
            }
        }

        return [
            'total'      => count($rows),
            'sections'   => $sections,
            'items'      => $items,
            'anomalies'  => $anomalies,
            'mismatches' => $mismatches,
        ];
    }
}
