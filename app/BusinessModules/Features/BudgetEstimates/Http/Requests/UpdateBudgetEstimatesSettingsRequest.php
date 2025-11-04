<?php

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBudgetEstimatesSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Проверка прав доступа на управление настройками модуля
        return $this->user()->can('budget-estimates.manage-settings');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Основные настройки смет
            'estimate_settings' => 'sometimes|array',
            'estimate_settings.auto_generate_numbers' => 'sometimes|boolean',
            'estimate_settings.number_template' => 'sometimes|string|max:100',
            'estimate_settings.default_vat_rate' => 'sometimes|numeric|min:0|max:100',
            'estimate_settings.default_overhead_rate' => 'sometimes|numeric|min:0|max:100',
            'estimate_settings.default_profit_rate' => 'sometimes|numeric|min:0|max:100',
            'estimate_settings.require_approval' => 'sometimes|boolean',
            'estimate_settings.allow_editing_approved' => 'sometimes|boolean',
            
            // Настройки импорта
            'import_settings' => 'sometimes|array',
            'import_settings.auto_match_confidence_threshold' => 'sometimes|numeric|min:0|max:100',
            'import_settings.auto_create_work_types' => 'sometimes|boolean',
            'import_settings.store_import_files' => 'sometimes|boolean',
            'import_settings.file_retention_days' => 'sometimes|integer|min:1|max:365',
            
            // Настройки экспорта
            'export_settings' => 'sometimes|array',
            'export_settings.default_format' => 'sometimes|string|in:excel,pdf,csv',
            'export_settings.include_justifications' => 'sometimes|boolean',
            'export_settings.watermark_drafts' => 'sometimes|boolean',
            
            // Настройки расчетов
            'calculation_settings' => 'sometimes|array',
            'calculation_settings.round_precision' => 'sometimes|integer|min:0|max:4',
            'calculation_settings.recalculate_on_change' => 'sometimes|boolean',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'estimate_settings.auto_generate_numbers' => 'автоматическая генерация номеров',
            'estimate_settings.number_template' => 'шаблон номера сметы',
            'estimate_settings.default_vat_rate' => 'НДС по умолчанию',
            'estimate_settings.default_overhead_rate' => 'накладные расходы по умолчанию',
            'estimate_settings.default_profit_rate' => 'плановая прибыль по умолчанию',
            'estimate_settings.require_approval' => 'требовать утверждение',
            'estimate_settings.allow_editing_approved' => 'разрешить редактирование утвержденных',
            
            'import_settings.auto_match_confidence_threshold' => 'порог автоматического сопоставления',
            'import_settings.auto_create_work_types' => 'автоматическое создание видов работ',
            'import_settings.store_import_files' => 'сохранять импортированные файлы',
            'import_settings.file_retention_days' => 'срок хранения файлов',
            
            'export_settings.default_format' => 'формат экспорта по умолчанию',
            'export_settings.include_justifications' => 'включать обоснования',
            'export_settings.watermark_drafts' => 'водяной знак на черновиках',
            
            'calculation_settings.round_precision' => 'точность округления',
            'calculation_settings.recalculate_on_change' => 'пересчитывать при изменении',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'estimate_settings.default_vat_rate.min' => 'Ставка НДС не может быть меньше 0%',
            'estimate_settings.default_vat_rate.max' => 'Ставка НДС не может быть больше 100%',
            'estimate_settings.default_overhead_rate.min' => 'Накладные расходы не могут быть меньше 0%',
            'estimate_settings.default_overhead_rate.max' => 'Накладные расходы не могут быть больше 100%',
            'estimate_settings.default_profit_rate.min' => 'Плановая прибыль не может быть меньше 0%',
            'estimate_settings.default_profit_rate.max' => 'Плановая прибыль не может быть больше 100%',
            
            'import_settings.auto_match_confidence_threshold.min' => 'Порог сопоставления не может быть меньше 0%',
            'import_settings.auto_match_confidence_threshold.max' => 'Порог сопоставления не может быть больше 100%',
            'import_settings.file_retention_days.min' => 'Срок хранения должен быть не менее 1 дня',
            'import_settings.file_retention_days.max' => 'Срок хранения не может превышать 365 дней',
            
            'export_settings.default_format.in' => 'Формат экспорта должен быть: excel, pdf или csv',
            
            'calculation_settings.round_precision.min' => 'Точность округления не может быть меньше 0',
            'calculation_settings.round_precision.max' => 'Точность округления не может быть больше 4',
        ];
    }

    /**
     * Handle a failed authorization attempt.
     */
    protected function failedAuthorization(): void
    {
        throw new \Illuminate\Auth\Access\AuthorizationException(
            'У вас нет прав на управление настройками модуля "Сметное дело"'
        );
    }
}

