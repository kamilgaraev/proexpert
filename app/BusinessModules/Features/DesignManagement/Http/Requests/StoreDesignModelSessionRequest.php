<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDesignModelSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer'],
            'model_set_id' => ['required', 'integer'],
            'model_set_revision' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:255'],
        ];
    }
}
