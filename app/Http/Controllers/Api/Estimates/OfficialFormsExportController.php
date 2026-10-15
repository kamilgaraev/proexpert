<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Estimates;

use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Estimates\OfficialFormsExportRequest;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use App\Services\ActReport\ActReportAccessService;
use App\Services\ActReport\OfficialFormsDownloadService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OfficialFormsExportController extends Controller
{
    public function __construct(
        private readonly OfficialFormsDownloadService $downloads,
        private readonly ActReportAccessService $access,
    ) {}

    public function exportKS2(OfficialFormsExportRequest $request, int $actId): BinaryFileResponse|JsonResponse
    {
        return $this->download($request, $actId, 'ks2');
    }

    public function exportKS3(OfficialFormsExportRequest $request, int $actId): BinaryFileResponse|JsonResponse
    {
        return $this->download($request, $actId, 'ks3');
    }

    public function exportBothForms(OfficialFormsExportRequest $request, int $actId): BinaryFileResponse|JsonResponse
    {
        return $this->download($request, $actId, 'ks-both');
    }

    private function download(OfficialFormsExportRequest $request, int $actId, string $form): BinaryFileResponse|JsonResponse
    {
        try {
            $actor = $request->user();
            if (! $actor instanceof User) throw new BusinessLogicException(trans_message('act_reports.access_denied'), 403);

            return $this->downloads->download($actor, $this->access->currentOrganizationId($request), $actId, $form, (string) $request->validated('format'));
        } catch (BusinessLogicException $exception) {
            $messageKey = match ($exception->getMessage()) {
                trans_message('act_reports.access_denied') => 'act_reports.access_denied',
                trans_message('act_reports.organization_not_found') => 'act_reports.organization_not_found',
                trans_message('act_reports.official_export_requires_approved_act') => 'act_reports.official_export_requires_approved_act',
                default => 'act_reports.export_failed',
            };
            $status = $exception->getCode();

            return AdminResponse::error(trans_message($messageKey), $status >= 400 && $status < 600 ? $status : 500);
        }
    }
}
