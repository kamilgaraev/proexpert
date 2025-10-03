<?php

namespace App\Http\Requests\Api\V1\Admin\CustomReport;

use Illuminate\Foundation\Http\FormRequest;

class CreateScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->can('view-reports');
    }

    public function rules(): array
    {
        return [
            'schedule_type' => 'required|string|in:daily,weekly,monthly,custom_cron',
            'schedule_config' => 'required|array',
            'schedule_config.time' => 'required_if:schedule_type,daily,weekly,monthly|date_format:H:i',
            'schedule_config.day_of_week' => 'required_if:schedule_type,weekly|integer|min:0|max:6',
            'schedule_config.day_of_month' => 'required_if:schedule_type,monthly|integer|min:1|max:31',
            'schedule_config.cron_expression' => 'required_if:schedule_type,custom_cron|string',
            'filters_preset' => 'nullable|array',
            'recipient_emails' => 'required|array|min:1',
            'recipient_emails.*' => 'email',
            'export_format' => 'required|string|in:csv,excel,pdf',
        ];
    }

    public function messages(): array
    {
        return [
            'schedule_type.required' => 'Тип расписания обязателен',
            'schedule_config.required' => 'Конфигурация расписания обязательна',
            'recipient_emails.required' => 'Необходимо указать хотя бы один email получателя',
            'export_format.required' => 'Формат экспорта обязателен',
        ];
    }
}

