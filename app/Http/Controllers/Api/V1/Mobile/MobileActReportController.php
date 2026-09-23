<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\ListMobileActsRequest;
use App\Http\Requests\Api\V1\Mobile\StoreMobileActFieldConfirmationRequest;
use App\Http\Responses\MobileResponse;
use App\Models\ContractPerformanceAct;
use App\Services\Mobile\MobileActReportService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class MobileActReportController extends Controller
{
    public function __construct(private readonly MobileActReportService $acts) {}

    public function index(ListMobileActsRequest $request): JsonResponse
    {
        try {
            return MobileResponse::success($this->acts->index($request, $request->validated()));
        } catch (BusinessLogicException $exception) {
            return $this->businessError($exception);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'index');
        }
    }

    public function show(Request $request, ContractPerformanceAct $act): JsonResponse
    {
        try {
            return MobileResponse::success($this->acts->show($request, (int) $act->getKey()));
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('act_reports.act_not_found'), 404);
        } catch (BusinessLogicException $exception) {
            return $this->businessError($exception);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'show', ['act_id' => (int) $act->getKey()]);
        }
    }

    public function fieldConfirm(StoreMobileActFieldConfirmationRequest $request, ContractPerformanceAct $act): JsonResponse
    {
        try {
            $result = $this->acts->fieldConfirm($request, (int) $act->getKey(), $request->validated());

            return MobileResponse::success($result['data'], null, $result['created'] ? 201 : 200);
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('act_reports.act_not_found'), 404);
        } catch (BusinessLogicException $exception) {
            return $this->businessError($exception);
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $code = $exception->getCode();

            return MobileResponse::error($exception->getMessage(), in_array($code, [403, 404, 409], true) ? $code : 422);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'field_confirm', ['act_id' => (int) $act->getKey()]);
        }
    }

    public function downloadFile(Request $request, ContractPerformanceAct $act, int $file): StreamedResponse|JsonResponse
    {
        try {
            return $this->acts->downloadFile($request, (int) $act->getKey(), $file);
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('act_reports.file_not_found'), 404);
        } catch (BusinessLogicException $exception) {
            return $this->businessError($exception);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'download_file', ['act_id' => (int) $act->getKey(), 'file_id' => $file]);
        }
    }

    private function businessError(BusinessLogicException $exception): JsonResponse
    {
        $code = $exception->getCode();

        return MobileResponse::error($exception->getMessage(), in_array($code, [403, 404, 409, 422], true) ? $code : 403);
    }

    private function failed(Request $request, Throwable $exception, string $action, array $context = []): JsonResponse
    {
        Log::error('mobile.act_report.'.$action.'.error', $context + [
            'user_id' => $request->user()?->id,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'exception' => $exception::class,
            'error' => $exception->getMessage(),
        ]);

        return MobileResponse::error('Не удалось выполнить операцию с актом.', 500);
    }
}
