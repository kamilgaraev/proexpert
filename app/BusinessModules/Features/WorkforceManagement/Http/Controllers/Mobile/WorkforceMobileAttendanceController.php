<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Controllers\Mobile;

use App\BusinessModules\Features\WorkforceManagement\Exceptions\WorkforceAttendanceException;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceAttendanceQrService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class WorkforceMobileAttendanceController extends Controller
{
    public function __construct(private readonly WorkforceAttendanceQrService $service)
    {
    }

    public function issueQr(Request $request): JsonResponse
    {
        try {
            $payload = $request->validate([
                'project_id' => ['nullable', 'integer'],
                'work_date' => ['required', 'date'],
            ]);

            $user = $request->user();

            if ($user === null) {
                return MobileResponse::error(trans_message('workforce.errors.qr_employee_not_linked'), 401);
            }

            return MobileResponse::success($this->service->issue($this->organizationId($request), $user, $payload));
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('errors.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'attendance.qr.issue');
        }
    }

    public function scanQr(Request $request): JsonResponse
    {
        try {
            $payload = $request->validate([
                'qr_token' => ['required', 'string', 'max:255'],
                'device_id' => ['nullable', 'string', 'max:120'],
            ]);

            $user = $request->user();

            if ($user === null) {
                return MobileResponse::error(trans_message('workforce.errors.qr_scan_forbidden'), 403);
            }

            return MobileResponse::success($this->service->scan($this->organizationId($request), $user, $payload));
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('errors.validation_failed'), 422, $exception->errors());
        } catch (WorkforceAttendanceException $exception) {
            return MobileResponse::error(
                $exception->getMessage(),
                $exception->statusCode(),
                ['code' => $exception->errorCode()]
            );
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'attendance.qr.scan');
        }
    }

    public function selfAttendance(Request $request): JsonResponse
    {
        try {
            $payload = $request->validate([
                'project_id' => ['nullable', 'integer'],
                'work_date' => ['required', 'date'],
                'device_id' => ['nullable', 'string', 'max:120'],
            ]);

            $user = $request->user();

            if ($user === null) {
                return MobileResponse::error(trans_message('workforce.errors.self_attendance_forbidden'), 403);
            }

            return MobileResponse::success($this->service->selfAttendance($this->organizationId($request), $user, $payload));
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('errors.validation_failed'), 422, $exception->errors());
        } catch (WorkforceAttendanceException $exception) {
            return MobileResponse::error(
                $exception->getMessage(),
                $exception->statusCode(),
                ['code' => $exception->errorCode()]
            );
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'attendance.self');
        }
    }

    public function history(Request $request): JsonResponse
    {
        try {
            $payload = $request->validate([
                'date_from' => ['required', 'date'],
                'date_to' => ['required', 'date', 'after_or_equal:date_from'],
                'project_id' => ['nullable', 'integer'],
            ]);

            $user = $request->user();

            if ($user === null) {
                return MobileResponse::error(trans_message('workforce.errors.self_attendance_forbidden'), 403);
            }

            return MobileResponse::success($this->service->history($this->organizationId($request), $user, $payload));
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('errors.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'attendance.history');
        }
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }

    private function failed(Request $request, \Throwable $exception, string $action): JsonResponse
    {
        Log::error('workforce.mobile_attendance_failed', [
            'action' => $action,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            'error' => $exception->getMessage(),
        ]);

        return MobileResponse::error(trans_message('workforce.errors.unexpected'), 500);
    }
}
