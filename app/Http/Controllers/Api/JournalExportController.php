<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Carbon\Carbon;

class JournalExportController extends Controller
{
    public function __construct(
        protected OfficialFormsExportService $exportService
    ) {}

    /**
     * Экспорт журнала в формате КС-6
     */
    public function exportKS6(Request $request, ConstructionJournal $journal): Response
    {
        $this->authorize('export', $journal);

        $validated = $request->validate([
            'format' => 'required|in:xlsx,pdf',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        $from = Carbon::parse($validated['date_from']);
        $to = Carbon::parse($validated['date_to']);
        $format = $validated['format'];

        $filePath = $format === 'pdf'
            ? $this->exportService->exportKS6ToPdf($journal, $from, $to)
            : $this->exportService->exportKS6ToExcel($journal, $from, $to);

        $filename = basename($filePath);
        $content = file_get_contents($filePath);
        
        unlink($filePath);

        $mimeType = $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response($content)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Экспорт ежедневной выписки из журнала
     */
    public function exportDailyReport(Request $request, ConstructionJournalEntry $entry): Response
    {
        $this->authorize('export', $entry->journal);

        $filePath = $this->exportService->exportDailyReportToPdf($entry);

        $filename = basename($filePath);
        $content = file_get_contents($filePath);
        
        unlink($filePath);

        return response($content)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Экспорт расширенного отчета
     */
    public function exportExtended(Request $request, ConstructionJournal $journal): Response
    {
        $this->authorize('export', $journal);

        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'include_materials' => 'boolean',
            'include_equipment' => 'boolean',
            'include_workers' => 'boolean',
        ]);

        $options = [
            'date_from' => $validated['date_from'] ?? $journal->start_date,
            'date_to' => $validated['date_to'] ?? ($journal->end_date ?? now()),
            'include_materials' => $validated['include_materials'] ?? true,
            'include_equipment' => $validated['include_equipment'] ?? true,
            'include_workers' => $validated['include_workers'] ?? true,
        ];

        $filePath = $this->exportService->exportExtendedReportToExcel($journal, $options);

        $filename = basename($filePath);
        $content = file_get_contents($filePath);
        
        unlink($filePath);

        return response($content)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }
}

