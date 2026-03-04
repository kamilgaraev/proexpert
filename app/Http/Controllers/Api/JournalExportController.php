<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Carbon\Carbon;

use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

class JournalExportController extends Controller
{
    public function __construct(
        protected OfficialFormsExportService $exportService
    ) {}

    /**
     * Экспорт журнала в формате КС-6
     */
    public function exportKS6(Request $request, ConstructionJournal $journal): JsonResponse
    {
        // Вместо authorize используем проверку через service или middleware, 
        // но оставим проверку прав
        $this->authorize('export', $journal);

        $validated = $request->validate([
            'format' => 'required|in:xlsx,pdf',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        $from = Carbon::parse($validated['date_from']);
        $to = Carbon::parse($validated['date_to']);
        $format = $validated['format'];

        $path = $format === 'pdf'
            ? $this->exportService->exportKS6ToPdf($journal, $from, $to)
            : $this->exportService->exportKS6ToExcel($journal, $from, $to);

        $url = $this->exportService->getFileService()->temporaryUrl($path, 15);

        return AdminResponse::success(['url' => $url]);
    }

    /**
     * Экспорт ежедневной выписки из журнала
     */
    public function exportDailyReport(Request $request, ConstructionJournalEntry $entry): JsonResponse
    {
        $this->authorize('export', $entry->journal);

        $path = $this->exportService->exportDailyReportToPdf($entry);
        $url = $this->exportService->getFileService()->temporaryUrl($path, 15);

        return AdminResponse::success(['url' => $url]);
    }

    /**
     * Экспорт расширенного отчета
     */
    public function exportExtended(Request $request, ConstructionJournal $journal): JsonResponse
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

        $path = $this->exportService->exportExtendedReportToExcel($journal, $options);
        $url = $this->exportService->getFileService()->temporaryUrl($path, 15);

        return AdminResponse::success(['url' => $url]);
    }
}

