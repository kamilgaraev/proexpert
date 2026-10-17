<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use App\Http\Responses\AdminResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

final class ShipProjectMaterialDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = (int) $this->user()?->current_organization_id;

        return [
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('organization_warehouses', 'id')
                ->where('organization_id', $organizationId)->where('is_active', true)->whereNull('deleted_at')],
            'responsible_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')
                ->where('is_active', true)],
            'notes' => ['nullable', 'string'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(AdminResponse::error(
            trans_message('errors.validation_failed'), 422, $validator->errors()->toArray(),
        ));
    }
}
