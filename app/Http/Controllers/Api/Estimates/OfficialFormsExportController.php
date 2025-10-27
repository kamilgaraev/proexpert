<?php

namespace App\Http\Controllers\Api\Estimates;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class OfficialFormsExportController extends Controller
{
    public function __construct(
        protected OfficialFormsExportService $exportService
    ) {}

    public function exportKS2(Request $request, int $actId): Response
    {
        $request->validate([
            'format' => 'required|in:xlsx,pdf',
        ]);

        $act = ContractPerformanceAct::with(['contract', 'completedWorks'])->findOrFail($actId);
        $contract = $act->contract;

        $format = $request->input('format', 'xlsx');

        $filePath = $format === 'pdf'
            ? $this->exportService->exportKS2ToPdf($act, $contract)
            : $this->exportService->exportKS2ToExcel($act, $contract);

        $filename = basename($filePath);
        $content = file_get_contents($filePath);
        
        unlink($filePath);

        $mimeType = $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response($content)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    public function exportKS3(Request $request, int $actId): Response
    {
        $request->validate([
            'format' => 'required|in:xlsx,pdf',
        ]);

        $act = ContractPerformanceAct::with(['contract'])->findOrFail($actId);
        $contract = $act->contract;

        $format = $request->input('format', 'xlsx');

        $filePath = $format === 'pdf'
            ? $this->exportService->exportKS3ToPdf($act, $contract)
            : $this->exportService->exportKS3ToExcel($act, $contract);

        $filename = basename($filePath);
        $content = file_get_contents($filePath);
        
        unlink($filePath);

        $mimeType = $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response($content)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    public function exportBothForms(Request $request, int $actId): Response
    {
        $request->validate([
            'format' => 'required|in:xlsx,pdf',
        ]);

        $act = ContractPerformanceAct::with(['contract', 'completedWorks'])->findOrFail($actId);
        $contract = $act->contract;

        $format = $request->input('format', 'xlsx');

        $ks2Path = $format === 'pdf'
            ? $this->exportService->exportKS2ToPdf($act, $contract)
            : $this->exportService->exportKS2ToExcel($act, $contract);

        $ks3Path = $format === 'pdf'
            ? $this->exportService->exportKS3ToPdf($act, $contract)
            : $this->exportService->exportKS3ToExcel($act, $contract);

        $zipFilename = "KS-2-3_{$act->number}_{$contract->number}.zip";
        $zipPath = storage_path("app/temp/{$zipFilename}");

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) === TRUE) {
            $zip->addFile($ks2Path, basename($ks2Path));
            $zip->addFile($ks3Path, basename($ks3Path));
            $zip->close();
        }

        $content = file_get_contents($zipPath);
        
        unlink($ks2Path);
        unlink($ks3Path);
        unlink($zipPath);

        return response($content)
            ->header('Content-Type', 'application/zip')
            ->header('Content-Disposition', "attachment; filename=\"{$zipFilename}\"");
    }
}
