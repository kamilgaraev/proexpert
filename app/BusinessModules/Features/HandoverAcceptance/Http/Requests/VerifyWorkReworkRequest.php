<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyWorkReworkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:accepted,rejected'],
            'comment' => ['required', 'string', 'max:2000'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'operation_key' => ['required', 'string', 'max:160'],
        ];
    }
}
