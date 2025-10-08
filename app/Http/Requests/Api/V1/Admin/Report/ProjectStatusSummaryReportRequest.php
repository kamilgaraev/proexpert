<?php

namespace App\Http\Requests\Api\V1\Admin\Report;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Project; // Используем для получения возможных статусов

class ProjectStatusSummaryReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (!$this->user()) {
            abort(401, 'Unauthorized');
        }

        return true;
    }

    public function rules(): array
    {
        // Получаем список возможных статусов из модели или константы, если есть
        // $validStatuses = Project::getPossibleStatuses(); // Пример
        $validStatuses = ['active', 'completed', 'planned', 'on_hold']; // Хардкод, если нет метода

        return [
            'status' => [
                'nullable',
                'string',
                Rule::in($validStatuses),
            ],
            'is_archived' => 'nullable|boolean',
        ];
    }
} 