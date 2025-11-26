<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\Enums\PersonnelTypeEnum;
use App\BusinessModules\Features\SiteRequests\Enums\EquipmentTypeEnum;
use Illuminate\Validation\Rules\Enum;

/**
 * Валидация обновления заявки
 */
class UpdateSiteRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            // Основные поля
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['sometimes', new Enum(SiteRequestPriorityEnum::class)],
            'required_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],

            // Материалы
            'material_id' => ['nullable', 'integer'],
            'material_name' => ['nullable', 'string', 'max:255'],
            'material_quantity' => ['nullable', 'numeric', 'min:0.001'],
            'material_unit' => ['nullable', 'string', 'max:50'],
            'delivery_address' => ['nullable', 'string'],
            'delivery_time_from' => ['nullable', 'date_format:H:i'],
            'delivery_time_to' => ['nullable', 'date_format:H:i'],
            'contact_person_name' => ['nullable', 'string', 'max:255'],
            'contact_person_phone' => ['nullable', 'string', 'max:50'],

            // Персонал
            'personnel_type' => ['nullable', new Enum(PersonnelTypeEnum::class)],
            'personnel_count' => ['nullable', 'integer', 'min:1', 'max:50'],
            'personnel_requirements' => ['nullable', 'string'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'work_hours_per_day' => ['nullable', 'integer', 'min:1', 'max:24'],
            'work_start_date' => ['nullable', 'date'],
            'work_end_date' => ['nullable', 'date', 'after_or_equal:work_start_date'],
            'work_location' => ['nullable', 'string'],
            'additional_conditions' => ['nullable', 'string'],

            // Техника
            'equipment_type' => ['nullable', new Enum(EquipmentTypeEnum::class)],
            'equipment_specs' => ['nullable', 'string'],
            'rental_start_date' => ['nullable', 'date'],
            'rental_end_date' => ['nullable', 'date', 'after_or_equal:rental_start_date'],
            'rental_hours_per_day' => ['nullable', 'integer', 'min:1', 'max:24'],
            'with_operator' => ['nullable', 'boolean'],
            'equipment_location' => ['nullable', 'string'],

            // Метаданные
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'title' => 'название',
            'priority' => 'приоритет',
            'required_date' => 'желаемая дата',
            'material_quantity' => 'количество',
            'personnel_count' => 'количество человек',
            'work_end_date' => 'дата окончания работ',
            'rental_end_date' => 'дата окончания аренды',
        ];
    }
}

