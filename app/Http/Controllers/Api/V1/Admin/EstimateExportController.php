<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Models\Estimate;
use App\Models\ConstructionJournal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Carbon\Carbon;

class EstimateExportController extends Controller
{
    public function __construct(
        protected OfficialFormsExportService $exportService
    ) {}

    /**
     * Экспорт формы КС-2 по смете
     */
    public function exportKS2(Request $request, int $projectId, int $estimateId): Response
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimate = Estimate::where('id', $estimateId)
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->with(['project', 'contract'])
            ->firstOrFail();

        $validated = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'act_number' => 'nullable|string',
        ]);

        // TODO: Реализовать экспорт КС-2 на основе сметы и данных журнала за период
        // Пока возвращаем заглушку
        return response()->json([
            'success' => false,
            'message' => 'Экспорт КС-2 по смете будет реализован в следующей версии',
        ], 501);
    }

    /**
     * Экспорт формы КС-3 по смете
     */
    public function exportKS3(Request $request, int $projectId, int $estimateId): Response
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimate = Estimate::where('id', $estimateId)
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->with(['project', 'contract'])
            ->firstOrFail();

        $validated = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        // TODO: Реализовать экспорт КС-3 на основе сметы
        return response()->json([
            'success' => false,
            'message' => 'Экспорт КС-3 по смете будет реализован в следующей версии',
        ], 501);
    }

    /**
     * Экспорт сводки по смете
     */
    public function exportSummary(Request $request, int $projectId, int $estimateId): Response
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimate = Estimate::where('id', $estimateId)
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->with(['sections.items', 'project', 'contract'])
            ->firstOrFail();

        $validated = $request->validate([
            'include_sections' => 'boolean',
            'include_prices' => 'boolean',
            'format' => 'required|in:pdf,xlsx',
        ]);

        // TODO: Реализовать экспорт сводки
        return response()->json([
            'success' => false,
            'message' => 'Экспорт сводки по смете будет реализован в следующей версии',
        ], 501);
    }

    /**
     * Экспорт формы КС-6 из журнала с фильтром по смете
     */
    public function exportKS6(Request $request, int $projectId, ConstructionJournal $journal): Response
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        if ($journal->project_id !== $projectId || $journal->organization_id !== $organizationId) {
            abort(404);
        }

        $validated = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'estimate_id' => 'nullable|exists:estimates,id',
        ]);

        $from = Carbon::parse($validated['date_from']);
        $to = Carbon::parse($validated['date_to']);

        // Если указан estimate_id, фильтруем записи журнала
        if (isset($validated['estimate_id'])) {
            // TODO: Добавить фильтрацию по estimate_id в экспорт
        }

        $filePath = $this->exportService->exportKS6ToPdf($journal, $from, $to);

        $filename = basename($filePath);
        $content = file_get_contents($filePath);
        
        unlink($filePath);

        return response($content)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Экспорт расширенного отчета из журнала с фильтром по смете
     */
    public function exportExtendedReport(Request $request, int $projectId, ConstructionJournal $journal): Response
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        if ($journal->project_id !== $projectId || $journal->organization_id !== $organizationId) {
            abort(404);
        }

        $validated = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'estimate_id' => 'nullable|exists:estimates,id',
            'include_volumes' => 'boolean',
            'include_workers' => 'boolean',
            'include_equipment' => 'boolean',
            'include_materials' => 'boolean',
            'format' => 'required|in:pdf,xlsx',
        ]);

        $options = [
            'date_from' => $validated['date_from'],
            'date_to' => $validated['date_to'],
            'include_materials' => $validated['include_materials'] ?? true,
            'include_equipment' => $validated['include_equipment'] ?? true,
            'include_workers' => $validated['include_workers'] ?? true,
        ];

        // Если указан estimate_id, фильтруем записи журнала
        if (isset($validated['estimate_id'])) {
            // TODO: Добавить фильтрацию по estimate_id в экспорт
        }

        $filePath = $this->exportService->exportExtendedReportToExcel($journal, $options);

        $filename = basename($filePath);
        $content = file_get_contents($filePath);
        
        unlink($filePath);

        $mimeType = $validated['format'] === 'pdf' 
            ? 'application/pdf' 
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response($content)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }
}

