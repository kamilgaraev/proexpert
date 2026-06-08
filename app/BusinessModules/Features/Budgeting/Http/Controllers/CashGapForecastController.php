<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Controllers;

use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastContext;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapScenarioAdjustment;
use App\BusinessModules\Features\Budgeting\Services\CashGapForecastReadService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class CashGapForecastController extends Controller
{
    private const MAX_FORECAST_RANGE_DAYS = 180;

    public function __construct(
        private readonly CashGapForecastReadService $forecastReadService,
    ) {
    }

    public function forecast(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate($this->rules());
            $validated['organization_id'] = $this->resolveOrganizationId($request, $validated);
            $validated['granularity'] = $validated['granularity'] ?? 'day';
            $validated['scenario'] = $validated['scenario'] ?? CashGapForecastContext::SCENARIO_BASE;
            $validated['scenario_adjustments'] = $validated['scenario_adjustments'] ?? [];

            $this->assertSupportedRange($validated['period_start'], $validated['period_end']);

            return AdminResponse::success(
                $this->forecastReadService->build($validated),
                trans_message('budgeting.cash_gap.loaded'),
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error(trans_message('budgeting.validation.invalid_value'), 422, $exception->errors());
        } catch (\InvalidArgumentException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('budgeting.cash_gap.forecast.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'filters' => $request->only([
                    'period_start',
                    'period_end',
                    'granularity',
                    'project_id',
                    'counterparty_id',
                    'budget_article_id',
                    'responsibility_center_id',
                    'currency',
                    'scenario',
                ]),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('budgeting.cash_gap.load_error'), 500);
        }
    }

    private function rules(): array
    {
        return [
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'granularity' => ['nullable', Rule::in(['day', 'week'])],
            'organization_id' => ['nullable', 'integer'],
            'current_organization_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'counterparty_id' => ['nullable', 'integer'],
            'budget_article_id' => ['nullable', 'string', 'max:64'],
            'responsibility_center_id' => ['nullable', 'string', 'max:64'],
            'currency' => ['nullable', 'string', 'size:3'],
            'scenario' => ['nullable', Rule::in([
                CashGapForecastContext::SCENARIO_BASE,
                CashGapForecastContext::SCENARIO_OPTIMISTIC,
                CashGapForecastContext::SCENARIO_PESSIMISTIC,
                CashGapForecastContext::SCENARIO_CUSTOM,
            ])],
            'scenario_adjustments' => ['nullable', 'array', 'max:50'],
            'scenario_adjustments.*.action' => ['required', Rule::in([
                CashGapScenarioAdjustment::ACTION_RESCHEDULE_PAYMENT,
                CashGapScenarioAdjustment::ACTION_CHANGE_INFLOW_PROBABILITY,
                CashGapScenarioAdjustment::ACTION_EXCLUDE_PAYMENT,
                CashGapScenarioAdjustment::ACTION_ADD_TEMPORARY_INFLOW,
                CashGapScenarioAdjustment::ACTION_ADD_TEMPORARY_FINANCING,
            ])],
            'scenario_adjustments.*.cash_flow_key' => ['nullable', 'string', 'max:255'],
            'scenario_adjustments.*.source_type' => ['nullable', 'string', 'max:64'],
            'scenario_adjustments.*.source_id' => ['nullable'],
            'scenario_adjustments.*.date' => ['nullable', 'date'],
            'scenario_adjustments.*.probability' => ['nullable', 'numeric', 'between:0,1'],
            'scenario_adjustments.*.amount' => ['nullable', 'numeric', 'min:0.01'],
            'scenario_adjustments.*.currency' => ['nullable', 'string', 'size:3'],
            'scenario_adjustments.*.description' => ['nullable', 'string', 'max:255'],
            'scenario_adjustments.*.reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function resolveOrganizationId(Request $request, array $validated): int
    {
        $currentOrganizationId = $request->attributes->get('current_organization_id')
            ?? $validated['current_organization_id']
            ?? null;

        if ($currentOrganizationId === null || (int) $currentOrganizationId <= 0) {
            throw new \InvalidArgumentException(trans_message('budgeting.organization_context_missing'));
        }

        $requestedOrganizationId = $validated['organization_id'] ?? null;

        if ($requestedOrganizationId !== null && (int) $requestedOrganizationId !== (int) $currentOrganizationId) {
            throw new \InvalidArgumentException(trans_message('budgeting.cash_gap.errors.organization_mismatch'));
        }

        return (int) $currentOrganizationId;
    }

    private function assertSupportedRange(string $start, string $end): void
    {
        $days = (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days;

        if ($days > self::MAX_FORECAST_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'period_end' => [trans_message('budgeting.cash_gap.errors.range_too_large')],
            ]);
        }
    }
}
