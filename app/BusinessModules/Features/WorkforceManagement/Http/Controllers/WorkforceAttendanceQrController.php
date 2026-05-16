<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Controllers;

use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceAttendanceQrService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class WorkforceAttendanceQrController extends Controller
{
    public function __construct(private readonly WorkforceAttendanceQrService $service)
    {
    }

    public function qrScans(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->audit((int) $request->attributes->get('current_organization_id')));
        } catch (\Throwable $exception) {
            Log::error('workforce.attendance_qr_audit_failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('workforce.errors.unexpected'), 500);
        }
    }
}
