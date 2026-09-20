<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\CompletedWork;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

final class CorrectCompletedWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'string', 'max:128'],
            'expected_version' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'min:10', 'max:5000'],
            'source_event_id' => ['nullable', 'string', 'max:128'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'completed_quantity' => ['sometimes', 'numeric', 'min:0'],
            'price' => ['prohibited'],
            'total_amount' => ['prohibited'],
        ];
    }
}
