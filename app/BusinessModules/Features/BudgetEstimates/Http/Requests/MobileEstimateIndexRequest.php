<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests;

use App\BusinessModules\Features\BudgetEstimates\Services\MobileBudgetEstimateService;
use App\Http\Responses\MobileResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

final class MobileEstimateIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::in(MobileBudgetEstimateService::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(MobileResponse::error(
            trans_message('budget_estimates.mobile.errors.validation_failed'),
            422,
            $validator->errors(),
        ));
    }
}
