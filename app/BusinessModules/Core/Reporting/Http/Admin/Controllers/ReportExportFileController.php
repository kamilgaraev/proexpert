<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Application\Contracts\ReadReportExportFileAction;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ReportExportRouteRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class ReportExportFileController
{
    public function __construct(private ReportHttpAuthorizationOrchestrator $authorization, private ReadReportExportFileAction $read) {}

    public function __invoke(ReportExportRouteRequest $request): StreamedResponse
    {
        $authorization = $this->authorization->download($request, $request->exportId());
        $file = $this->read->handle($authorization['context'], $request->exportId());

        return new StreamedResponse($file->writeToOutput, 200, [
            'Content-Disposition' => 'attachment; filename="'.$file->filename.'"',
            'Content-Type' => $file->mimeType, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
