<?php

namespace App\Http\Controllers\Api\Estimates;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Services\Contract\ContractAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OfficialFormsExportController extends Controller
{
    public function __construct(
        protected OfficialFormsExportService $exportService,
        protected ContractAccessService $contractAccessService
    ) {}

    public function exportKS2(Request $request, int $actId): Response
    {
        $request->validate([
            'format' => 'required|in:xlsx,pdf',
        ]);

        $act = $this->resolveAccessibleAct($request, $actId, [
            'completedWorks.workType.measurementUnit',
        ]);
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

        $act = $this->resolveAccessibleAct($request, $actId, [
            'contract.estimate',
        ]);
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

        $act = $this->resolveAccessibleAct($request, $actId, [
            'contract.estimate',
            'completedWorks.workType.measurementUnit',
        ]);
        $contract = $act->contract;

        $format = $request->input('format', 'xlsx');

        $ks2Path = $format === 'pdf'
            ? $this->exportService->exportKS2ToPdf($act, $contract)
            : $this->exportService->exportKS2ToExcel($act, $contract);

        $ks3Path = $format === 'pdf'
            ? $this->exportService->exportKS3ToPdf($act, $contract)
            : $this->exportService->exportKS3ToExcel($act, $contract);

        $actNumber = $act->act_document_number ?? $act->id;
        $zipFilename = "KS-2-3_{$actNumber}_{$contract->number}.zip";
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

    private function resolveAccessibleAct(Request $request, int $actId, array $relations = []): ContractPerformanceAct
    {
        $act = ContractPerformanceAct::with(array_merge([
            'contract.contractor',
            'contract.project.organization',
            'contract.organization',
        ], $relations))->findOrFail($actId);

        $organizationId = $this->getOrganizationId($request);

        if (!$organizationId || !$act->contract || !$this->contractAccessService->canAccess($act->contract, $organizationId)) {
            abort(404);
        }

        return $act;
    }

    private function getOrganizationId(Request $request): ?int
    {
        $organization = $request->attributes->get('current_organization');

        return $organization?->id
            ?? $request->user()?->organization_id
            ?? $request->user()?->current_organization_id;
    }
}
