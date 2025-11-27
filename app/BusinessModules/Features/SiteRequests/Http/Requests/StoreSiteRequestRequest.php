<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\Enums\PersonnelTypeEnum;
use App\BusinessModules\Features\SiteRequests\Enums\EquipmentTypeEnum;
use Illuminate\Validation\Rules\Enum;

/**
 * Валидация создания заявки
 */
class StoreSiteRequestRequest extends FormRequest
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
        $rules = [
            // Основные поля
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'request_type' => ['required', new Enum(SiteRequestTypeEnum::class)],
            'priority' => ['sometimes', new Enum(SiteRequestPriorityEnum::class)],
            'required_date' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string'],
            'materials' => ['nullable', 'array'],
        ];

        // Правила для материалов
        if ($this->input('request_type') === SiteRequestTypeEnum::MATERIAL_REQUEST->value) {
            // Если передан массив materials
            if ($this->has('materials') && is_array($this->input('materials'))) {
                $rules = array_merge($rules, [
                    'materials.*.material_id' => ['nullable', 'integer'],
                    'materials.*.name' => ['required_without:materials.*.material_id', 'nullable', 'string', 'max:255'],
                    'materials.*.quantity' => ['required', 'numeric', 'min:0.001'],
                    'materials.*.unit' => ['required', 'string', 'max:50'],
                    'materials.*.note' => ['nullable', 'string'],
                    
                    // Общие поля доставки для всей заявки
                    'delivery_address' => ['nullable', 'string'],
                    'delivery_time_from' => ['nullable', 'date_format:H:i'],
                    'delivery_time_to' => ['nullable', 'date_format:H:i', 'after:delivery_time_from'],
                    'contact_person_name' => ['nullable', 'string', 'max:255'],
                    'contact_person_phone' => ['nullable', 'string', 'max:50'],
                ]);
            } else {
                // Старая логика для одиночного материала
                $rules = array_merge($rules, [
                    'material_id' => ['nullable', 'integer'],
                    'material_name' => ['required_without:material_id', 'nullable', 'string', 'max:255'],
                    'material_quantity' => ['required', 'numeric', 'min:0.001'],
                    'material_unit' => ['required', 'string', 'max:50'],
                    'delivery_address' => ['nullable', 'string'],
                    'delivery_time_from' => ['nullable', 'date_format:H:i'],
                    'delivery_time_to' => ['nullable', 'date_format:H:i', 'after:delivery_time_from'],
                    'contact_person_name' => ['nullable', 'string', 'max:255'],
                    'contact_person_phone' => ['nullable', 'string', 'max:50'],
                ]);
            }
        }

        // Правила для персонала
        if ($this->input('request_type') === SiteRequestTypeEnum::PERSONNEL_REQUEST->value) {
            $rules = array_merge($rules, [
                'personnel_type' => ['required', new Enum(PersonnelTypeEnum::class)],
                'personnel_count' => ['required', 'integer', 'min:1', 'max:50'],
                'personnel_requirements' => ['nullable', 'string'],
                'hourly_rate' => ['nullable', 'numeric', 'min:0'],
                'work_hours_per_day' => ['nullable', 'integer', 'min:1', 'max:24'],
                'work_start_date' => ['nullable', 'date', 'after_or_equal:today'],
                'work_end_date' => ['nullable', 'date', 'after_or_equal:work_start_date'],
                'work_location' => ['nullable', 'string'],
                'additional_conditions' => ['nullable', 'string'],
            ]);
        }

        // Правила для техники
        if ($this->input('request_type') === SiteRequestTypeEnum::EQUIPMENT_REQUEST->value) {
            $rules = array_merge($rules, [
                'equipment_type' => ['required', new Enum(EquipmentTypeEnum::class)],
                'equipment_specs' => ['nullable', 'string'],
                'rental_start_date' => ['required', 'date', 'after_or_equal:today'],
                'rental_end_date' => ['nullable', 'date', 'after_or_equal:rental_start_date'],
                'rental_hours_per_day' => ['nullable', 'integer', 'min:1', 'max:24'],
                'with_operator' => ['nullable', 'boolean'],
                'equipment_location' => ['nullable', 'string'],
            ]);
        }

        // Метаданные
        $rules['metadata'] = ['nullable', 'array'];

        return $rules;
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'project_id' => 'проект',
            'title' => 'название',
            'description' => 'описание',
            'request_type' => 'тип заявки',
            'priority' => 'приоритет',
            'required_date' => 'желаемая дата',
            'material_name' => 'название материала',
            'material_quantity' => 'количество',
            'material_unit' => 'единица измерения',
            'personnel_type' => 'тип персонала',
            'personnel_count' => 'количество человек',
            'work_start_date' => 'дата начала работ',
            'work_end_date' => 'дата окончания работ',
            'equipment_type' => 'тип техники',
            'rental_start_date' => 'дата начала аренды',
            'rental_end_date' => 'дата окончания аренды',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'required' => 'Поле :attribute обязательно для заполнения',
            'required_without' => 'Поле :attribute обязательно, если не указано :values',
            'date' => 'Поле :attribute должно быть датой',
            'after_or_equal' => 'Поле :attribute должно быть не раньше :date',
            'after' => 'Поле :attribute должно быть позже :date',
            'min' => 'Поле :attribute должно быть не менее :min',
            'max' => 'Поле :attribute должно быть не более :max',
            'numeric' => 'Поле :attribute должно быть числом',
            'integer' => 'Поле :attribute должно быть целым числом',
        ];
    }
}

