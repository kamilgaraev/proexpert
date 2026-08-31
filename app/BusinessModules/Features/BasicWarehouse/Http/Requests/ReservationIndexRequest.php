<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReservationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $search = $this->input('search');

        if (is_string($search)) {
            $search = trim($search);
            $this->merge(['search' => $search !== '' ? $search : null]);
        }
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'min:3', 'max:100'],
        ];
    }
}
