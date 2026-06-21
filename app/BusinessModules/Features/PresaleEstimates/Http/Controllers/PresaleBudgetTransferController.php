<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\PresaleEstimates\Http\Controllers;

use App\BusinessModules\Features\PresaleEstimates\Exceptions\PresaleEstimateBudgetTransferException;
use App\BusinessModules\Features\PresaleEstimates\Http\Requests\PresaleBudgetTransferConvertRequest;
use App\BusinessModules\Features\PresaleEstimates\Http\Requests\PresaleBudgetTransferRequest;
use App\BusinessModules\Features\PresaleEstimates\Services\PresaleEstimateBudgetConversionService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

use function trans_message;

final class PresaleBudgetTransferController extends Controller
{
    public function __construct(
        private readonly PresaleEstimateBudgetConversionService $service
    ) {}

    public function preview(PresaleBudgetTransferRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->service->preview($this->organizationId($request), $this->user($request), $request->validated()),
                trans_message('presale_estimates.budget_transfer.preview_ready')
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, 'presale_estimates.budget_transfer.errors.preview');
        }
    }

    public function validateTransfer(PresaleBudgetTransferRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->service->validateTransfer($this->organizationId($request), $this->user($request), $request->validated()),
                trans_message('presale_estimates.budget_transfer.validation_ready')
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, 'presale_estimates.budget_transfer.errors.validate');
        }
    }

    public function convert(PresaleBudgetTransferConvertRequest $request): JsonResponse
    {
        try {
            $result = $this->service->convert($this->organizationId($request), $this->user($request), $request->validated());
            $statusCode = ($result['status'] ?? null) === 'already_converted' ? 200 : 201;
            $message = ($result['status'] ?? null) === 'already_converted'
                ? trans_message('presale_estimates.budget_transfer.already_completed')
                : trans_message('presale_estimates.budget_transfer.converted');

            return AdminResponse::success($result, $message, $statusCode);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'presale_estimates.budget_transfer.errors.convert');
        }
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new PresaleEstimateBudgetTransferException(trans_message('auth.unauthorized'), 401);
        }

        return $user;
    }

    private function failure(Throwable $exception, string $translationKey): JsonResponse
    {
        if ($exception instanceof PresaleEstimateBudgetTransferException) {
            return AdminResponse::error($exception->getMessage(), $exception->statusCode(), null, [
                'blockers' => $exception->blockers(),
                'warnings' => $exception->warnings(),
            ]);
        }

        if ($exception instanceof ValidationException) {
            return AdminResponse::error($this->validationMessage($exception, $translationKey), 422, $exception->errors());
        }

        if ($exception instanceof ModelNotFoundException) {
            return AdminResponse::error(trans_message('presale_estimates.budget_transfer.errors.not_found'), 404);
        }

        Log::error($translationKey, [
            'user_id' => auth()->id(),
            'message' => $exception->getMessage(),
        ]);

        return AdminResponse::error(trans_message($translationKey), 500);
    }

    private function validationMessage(ValidationException $exception, string $translationKey): string
    {
        foreach ($exception->errors() as $messages) {
            if (is_array($messages) && isset($messages[0]) && is_string($messages[0])) {
                return $messages[0];
            }
        }

        return trans_message($translationKey);
    }
}
