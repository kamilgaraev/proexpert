<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Controllers;

use App\BusinessModules\Features\Crm\Exceptions\DealConversionException;
use App\BusinessModules\Features\Crm\Http\Requests\DealConversionConvertRequest;
use App\BusinessModules\Features\Crm\Http\Requests\DealConversionPreviewRequest;
use App\BusinessModules\Features\Crm\Http\Requests\DealConversionValidateRequest;
use App\BusinessModules\Features\Crm\Services\DealConversionWizardService;
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

final class DealConversionWizardController extends Controller
{
    public function __construct(
        private readonly DealConversionWizardService $service
    ) {}

    public function preview(DealConversionPreviewRequest $request, string $id): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->service->preview($this->organizationId($request), $id, $request->validated(), $this->user($request)),
                trans_message('crm.conversion.preview_ready')
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, 'crm.conversion.errors.preview');
        }
    }

    public function validateConversion(DealConversionValidateRequest $request, string $id): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->service->validateConversion($this->organizationId($request), $id, $request->validated(), $this->user($request)),
                trans_message('crm.conversion.validation_ready')
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, 'crm.conversion.errors.validate');
        }
    }

    public function convert(DealConversionConvertRequest $request, string $id): JsonResponse
    {
        try {
            $result = $this->service->convert($this->organizationId($request), $id, $request->validated(), $request);
            $message = ($result['status'] ?? null) === 'already_converted'
                ? trans_message('crm.conversion.already_completed')
                : trans_message('crm.conversion.converted');
            $statusCode = ($result['status'] ?? null) === 'already_converted' ? 200 : 201;

            return AdminResponse::success($result, $message, $statusCode);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'crm.conversion.errors.convert');
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
            throw new DealConversionException(trans_message('auth.unauthorized'), 401);
        }

        return $user;
    }

    private function failure(Throwable $exception, string $translationKey): JsonResponse
    {
        if ($exception instanceof DealConversionException) {
            return AdminResponse::error($exception->getMessage(), $exception->statusCode(), null, [
                'blockers' => $exception->blockers(),
                'warnings' => $exception->warnings(),
            ]);
        }

        if ($exception instanceof ValidationException) {
            return AdminResponse::error($this->validationMessage($exception, $translationKey), 422, $exception->errors());
        }

        if ($exception instanceof ModelNotFoundException) {
            return AdminResponse::error(trans_message('crm.errors.not_found'), 404);
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
