<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Estimate;

use Illuminate\Foundation\Http\FormRequest;

final class CompareEstimateVersionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version_a_id' => ['required', 'integer'],
            'version_b_id' => ['required', 'integer', 'different:version_a_id'],
        ];
    }
}
