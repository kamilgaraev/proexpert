<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CfoDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'responsibility_center_id' => ['nullable', 'string', 'max:64'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'period_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'forecast_days' => ['nullable', 'integer', 'min:1', 'max:92'],
            'currency' => ['nullable', 'string', 'size:3'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $organizationId = (int) $this->attributes->get('current_organization_id');
            $companyId = $this->input('company_id');

            if ($companyId !== null && (int) $companyId !== $organizationId) {
                $validator->errors()->add('company_id', trans_message('payments.cfo_dashboard.company_scope_invalid'));
            }
        });
    }

    public function filters(): array
    {
        $validated = $this->validated();
        $periodDays = (int) ($validated['period_days'] ?? 30);
        $forecastDays = (int) ($validated['forecast_days'] ?? 30);
        $today = CarbonImmutable::today();
        $periodStart = isset($validated['period_start'])
            ? CarbonImmutable::parse((string) $validated['period_start'])
            : $today->subDays($periodDays - 1);
        $periodEnd = isset($validated['period_end'])
            ? CarbonImmutable::parse((string) $validated['period_end'])
            : $today->addDays($forecastDays);

        return [
            'organization_id' => (int) $this->attributes->get('current_organization_id'),
            'company_id' => $validated['company_id'] ?? null,
            'project_id' => isset($validated['project_id']) ? (int) $validated['project_id'] : null,
            'responsibility_center_id' => $validated['responsibility_center_id'] ?? null,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'currency' => isset($validated['currency']) ? mb_strtoupper((string) $validated['currency']) : null,
            'limit' => (int) ($validated['limit'] ?? 10),
        ];
    }
}
