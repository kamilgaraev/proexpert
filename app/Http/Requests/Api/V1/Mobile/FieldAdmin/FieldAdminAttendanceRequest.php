<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile\FieldAdmin;

use Illuminate\Foundation\Http\FormRequest;

final class FieldAdminAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
