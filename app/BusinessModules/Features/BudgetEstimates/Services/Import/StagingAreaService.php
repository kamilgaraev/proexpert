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

        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $orchestrator = app(ImportFormatOrchestrator::class);
        $handler = $orchestrator->getHandler($session->options['format_handler'] ?? 'generic');

        Log::info("[StagingArea] Using handler: {$handler->getSlug()}");

        $content = ($extension === 'xml') ? file_get_contents($fullPath) : \PhpOffice\PhpSpreadsheet\IOFactory::load($fullPath);
        $result = $handler->parse($session, $content);
        
        $rows = $result['items'] ?? [];
        
        // If handler already returned segments/sections, we might need to merge them 
        // OR just rely on the fact that items might have 'is_section' flag
        if (!empty($result['sections'])) {
            $rows = array_merge($result['sections'], $rows);
            // Sort by row_number if available
            usort($rows, fn($a, $b) => ($a['row_number'] ?? 0) <=> ($b['row_number'] ?? 0));
        }

        $mapper = app(ImportRowMapper::class);

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
