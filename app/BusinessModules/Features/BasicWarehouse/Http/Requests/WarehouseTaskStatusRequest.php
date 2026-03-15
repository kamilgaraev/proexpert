<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WarehouseTaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'required|in:draft,queued,in_progress,blocked,completed,cancelled',
            'completed_quantity' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ];
    }
}
