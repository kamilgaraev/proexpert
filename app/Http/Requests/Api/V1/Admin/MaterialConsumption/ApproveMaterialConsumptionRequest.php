<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\MaterialConsumption;

use Illuminate\Foundation\Http\FormRequest;

final class ApproveMaterialConsumptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [];
    }
}
