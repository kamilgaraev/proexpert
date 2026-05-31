<?php

namespace App\Http\Requests\Api\V1\Admin\Material;

use App\Http\Responses\AdminResponse;
use App\Models\Material;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use function trans_message;

class UpdateMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        $organizationId = $this->getOrganizationId();

        /** @var Material|string|null $material */
        $material = $this->route('material');

        if ($material && !($material instanceof Material)) {
            $material = Material::find($material);
        }

        $materialId = $material?->id;

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('materials', 'name')
                    ->where(function ($query) use ($organizationId) {
                        return $query->where('organization_id', $organizationId)
                                    ->whereNull('deleted_at');
                    })
                    ->ignore($materialId),
            ],
            'code' => 'sometimes|nullable|string|max:50',
            'measurement_unit_id' => [
                'sometimes',
                'required',
                'integer',
                $this->measurementUnitExistsRule($organizationId),
            ],
            'description' => 'sometimes|nullable|string|max:1000',
            'category' => 'sometimes|nullable|string|max:100',
            'default_price' => 'sometimes|nullable|numeric|min:0',
            'additional_properties' => 'sometimes|nullable|array',
            'is_active' => 'sometimes|boolean',

            'external_code' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                Rule::unique('materials', 'external_code')
                    ->where(function ($query) use ($organizationId) {
                        return $query->where('organization_id', $organizationId)
                                    ->whereNull('deleted_at');
                    })
                    ->ignore($materialId),
            ],
            'sbis_nomenclature_code' => 'sometimes|nullable|string|max:100',
            'sbis_unit_code' => 'sometimes|nullable|string|max:100',
            'consumption_rates' => 'sometimes|nullable|array',
            'consumption_rates.*' => 'numeric|min:0',
            'accounting_data' => 'sometimes|nullable|array',
            'use_in_accounting_reports' => 'sometimes|nullable|boolean',
            'accounting_account' => 'sometimes|nullable|string|max:50',
        ];
    }

    private function getOrganizationId(): ?int
    {
        $organizationId = $this->attributes->get('current_organization_id')
            ?? $this->user()?->current_organization_id;

        return $organizationId ? (int) $organizationId : null;
    }

    private function measurementUnitExistsRule(?int $organizationId)
    {
        return Rule::exists('measurement_units', 'id')
            ->where(function ($query) use ($organizationId): void {
                $query->whereNull('deleted_at')
                    ->where(function ($scope) use ($organizationId): void {
                        $scope->where('is_system', true);

                        if ($organizationId !== null) {
                            $scope->orWhere('organization_id', $organizationId);
                        }
                    });
            });
    }

    public function messages(): array
    {
        return [
            'name.required' => trans_message('materials.validation.name_required'),
            'name.unique' => trans_message('materials.validation.name_unique'),
            'measurement_unit_id.required' => trans_message('materials.validation.measurement_unit_required'),
            'measurement_unit_id.exists' => trans_message('materials.validation.measurement_unit_exists'),
            'external_code.unique' => trans_message('materials.validation.external_code_unique'),
            'default_price.min' => trans_message('materials.validation.default_price_min'),
            'consumption_rates.*.min' => trans_message('materials.validation.consumption_rate_min'),
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $errors = (new ValidationException($validator))->errors();

        throw new HttpResponseException(
            AdminResponse::fromPayload([
                'success' => false,
                'message' => trans_message('errors.validation_failed'),
                'errors' => $errors,
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY)
        );
    }
}
