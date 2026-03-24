<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RateCoefficient\RateCoefficientAppliesToEnum;
use App\Enums\RateCoefficient\RateCoefficientScopeEnum;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Services\RateCoefficient\RateCoefficientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use function trans_message;

class RateCoefficientApplyController extends Controller
{
    public function __construct(private readonly RateCoefficientService $rateCoefficientService)
    {
    }

    public function apply(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'original_value' => ['required', 'numeric', 'min:0'],
                'applies_to' => ['required', Rule::in(RateCoefficientAppliesToEnum::values())],
                'scope' => ['nullable', Rule::in(RateCoefficientScopeEnum::values())],
                'contextual_ids' => ['nullable', 'array'],
                'date' => ['nullable', 'date_format:Y-m-d'],
            ]);

            $organizationId = $request->attributes->get('current_organization_id')
                ?? $request->user()?->current_organization_id;

            if (!$organizationId) {
                return AdminResponse::error(
                    trans_message('rate_coefficients.organization_not_found'),
                    Response::HTTP_BAD_REQUEST
                );
            }

            $result = $this->rateCoefficientService->calculateAdjustedValueDetailed(
                (int) $organizationId,
                (float) $validated['original_value'],
                $validated['applies_to'],
                $validated['scope'] ?? null,
                $validated['contextual_ids'] ?? [],
                $validated['date'] ?? null
            );

            return AdminResponse::success($result);
        } catch (ValidationException $e) {
            return AdminResponse::error(
                trans_message('estimate.validation_error'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->errors()
            );
        } catch (Throwable $e) {
            Log::error('rate_coefficients.apply.error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('rate_coefficients.apply_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
