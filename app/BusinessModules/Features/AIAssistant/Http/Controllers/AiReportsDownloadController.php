<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportAccessService;
use App\Http\Controllers\Controller;
use App\Services\Storage\FileService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AiReportsDownloadController extends Controller
{
    public function __construct(private readonly FileService $files, private readonly AssistantReportAccessService $access) {}

    public function download(Request $request, string $token): StreamedResponse
    {
        $user = $request->user();
        if ($user === null) {
            throw new AccessDeniedHttpException();
        }
        try {
            $report = $this->access->resolve($token, $user);
        } catch (DecryptException) {
            throw new AccessDeniedHttpException();
        }
        $stream = $this->files->readCurrent($report->storage_path);
        return response()->streamDownload(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, basename($report->storage_path), ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store']);
    }
}
