<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RateCoefficient\RateCoefficientAppliesToEnum;
use App\Enums\RateCoefficient\RateCoefficientScopeEnum;
use App\Http\Controllers\Controller;
use App\Services\RateCoefficient\RateCoefficientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class RateCoefficientApplyController extends Controller
{
    public function __construct(private readonly RateCoefficientService $rateCoefficientService)
    {
    }

    /**
     * Быстрый расчёт значения с учётом коэффициентов.
     *
     * POST /api/v1/admin/rate-coefficients/apply
     *
     * Body example:
     * {
     *   "original_value": 120,
     *   "applies_to": "material_norms",
     *   "scope": "project",           // опционально
     *   "contextual_ids": {"project_id": 5, "material_id": 67},
     *   "date": "2025-07-17"          // опционально
     * }
     */
    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'original_value'  => ['required', 'numeric', 'min:0'],
            'applies_to'      => ['required', Rule::in(RateCoefficientAppliesToEnum::values())],
            'scope'           => ['nullable', Rule::in(RateCoefficientScopeEnum::values())],
            'contextual_ids'  => ['nullable', 'array'],
            'date'            => ['nullable', 'date_format:Y-m-d'],
        ]);

        // Определяем организацию (из middleware organization.context) или из пользователя
        $organizationId = $request->attributes->get('current_organization_id')
            ?? $request->user()?->current_organization_id;
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Не удалось определить организацию.'
            ], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->rateCoefficientService->calculateAdjustedValueDetailed(
            (int)$organizationId,
            (float)$validated['original_value'],
            $validated['applies_to'],
            $validated['scope'] ?? null,
            $validated['contextual_ids'] ?? [],
            $validated['date'] ?? null
        );

        return response()->json($result);
    }
} 