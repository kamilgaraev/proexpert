<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile\FieldAdmin;

use Illuminate\Foundation\Http\FormRequest;

final class FieldAdminCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ];
    }
}
