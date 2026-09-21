<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreWorkReworkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'quantity_line_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'string', 'regex:/^\d{1,18}(\.\d{1,6})?$/D'],
            'responsible_user_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:2000'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'operation_key' => ['required', 'string', 'max:160'],
        ];
    }
}
