<?php

declare(strict_types=1);

namespace App\Services\ActReport;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\ContractPerformanceAct;
use App\Models\User;
use App\Services\Contract\ContractAccessService;
use App\Services\Storage\FileService;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

final class OfficialFormsDownloadService
{
    public function __construct(
        private readonly OfficialFormsExportService $exporter,
        private readonly FileService $files,
        private readonly ContractAccessService $contracts,
        private readonly AuthorizationService $authorization,
    ) {}

    public function download(User $actor, int $organizationId, int $actId, string $form, string $format): BinaryFileResponse
    {
        if (! in_array($form, ['ks2', 'ks3', 'ks-both'], true) || ! in_array($format, ['xlsx', 'pdf'], true)) {
            throw new BusinessLogicException(trans_message('act_reports.export_failed'), 422);
        }
        $act = ContractPerformanceAct::with(['contract.contractor', 'contract.organization', 'contract.project.organization', 'completedWorks.workType.measurementUnit'])->findOrFail($actId);
        $contract = $act->contract;
        if (! $contract || ! $this->contracts->canAccess($contract, $organizationId)) abort(404);
        $permission = $format === 'pdf' ? ActReportAccessService::PERMISSION_EXPORT_PDF : ActReportAccessService::PERMISSION_EXPORT_EXCEL;
        if (! $this->authorization->can($actor, $permission, ['organization_id' => $organizationId])) {
            throw new BusinessLogicException(trans_message('act_reports.access_denied'), 403);
        }
        $temporaryFiles = [];
        $servedPath = null;
        try {
            $entries = [];
            foreach ($form === 'ks-both' ? ['ks2', 'ks3'] : [$form] as $document) {
                $key = match ([$document, $format]) {
                    ['ks2', 'pdf'] => $this->exporter->exportKS2ToPdf($act, $contract),
                    ['ks3', 'pdf'] => $this->exporter->exportKS3ToPdf($act, $contract),
                    ['ks2', 'xlsx'] => $this->exporter->exportKS2ToExcel($act, $contract),
                    ['ks3', 'xlsx'] => $this->exporter->exportKS3ToExcel($act, $contract),
                };
                if (! str_starts_with($key, 'org-'.$contract->organization_id.'/')) throw new RuntimeException('Unexpected export organization');
                $localPath = $this->temporaryFile();
                $temporaryFiles[] = $localPath;
                $input = $this->files->disk($contract->organization)->readStream($key);
                if (! is_resource($input)) throw new RuntimeException('Export stream unavailable');
                try {
                    $output = fopen($localPath, 'wb');
                    if ($output === false) throw new RuntimeException('Export temporary file unavailable');
                    try {
                        $copied = stream_copy_to_stream($input, $output);
                        if ($copied === false || $copied === 0) throw new RuntimeException('Export stream copy failed');
                    } finally {
                        fclose($output);
                    }
                } finally {
                    fclose($input);
                }
                $entries[$this->filename($document === 'ks2' ? 'KS-2' : 'KS-3', $act, $format)] = $localPath;
            }
            $downloadPath = reset($entries);
            $filename = array_key_first($entries);
            $mime = $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            if ($form === 'ks-both') {
                $downloadPath = $this->temporaryFile();
                $temporaryFiles[] = $downloadPath;
                $zip = new ZipArchive;
                if ($zip->open($downloadPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Archive unavailable');
                $opened = true;
                try {
                    foreach ($entries as $name => $path) {
                        if (! $zip->addFile($path, $name)) throw new RuntimeException('Archive entry failed');
                    }
                    $closed = $zip->close();
                    $opened = false;
                    if (! $closed) throw new RuntimeException('Archive completion failed');
                } finally {
                    if ($opened) $zip->close();
                }
                $filename = $this->filename('KS-2-3', $act, 'zip');
                $mime = 'application/zip';
            }
            clearstatcache(true, $downloadPath);
            $response = response()->download($downloadPath, $filename, ['Content-Type' => $mime])->deleteFileAfterSend(true);
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');
            $servedPath = $downloadPath;

            return $response;
        } catch (BusinessLogicException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('act_reports.official_download_failed', ['act_id' => $actId, 'organization_id' => $organizationId, 'exception' => $exception::class]);
            throw new BusinessLogicException(trans_message('act_reports.export_failed'), 500);
        } finally {
            foreach ($temporaryFiles as $path) {
                if ($path !== $servedPath && is_file($path)) unlink($path);
            }
        }
    }

    private function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'most-act-export-');
        if ($path === false) throw new RuntimeException('Export temporary file creation failed');

        return $path;
    }

    private function filename(string $prefix, ContractPerformanceAct $act, string $extension): string
    {
        $name = $prefix.'_'.($act->act_document_number ?? $act->id).'_'.$act->contract->number;
        $name = preg_replace('/[\\\\\/\x00-\x1F\x7F<>:"|?*]+/u', '_', $name) ?? $prefix.'_'.$act->id;

        return rtrim(mb_strcut($name, 0, 200, 'UTF-8'), '. ').'.'.$extension;
    }
}
