<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexWarehouseMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'material_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'movement_type' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in([
                    WarehouseMovement::TYPE_RECEIPT,
                    WarehouseMovement::TYPE_WRITE_OFF,
                    WarehouseMovement::TYPE_TRANSFER_IN,
                    WarehouseMovement::TYPE_TRANSFER_OUT,
                    WarehouseMovement::TYPE_ADJUSTMENT,
                    WarehouseMovement::TYPE_RETURN,
                    'reservation',
                    'unreservation',
                    'reserved_issue',
                ]),
            ],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
