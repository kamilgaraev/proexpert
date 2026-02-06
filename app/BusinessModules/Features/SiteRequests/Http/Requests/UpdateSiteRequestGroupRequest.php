<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;

/**
 * Валидация обновления группы заявок
 */
class UpdateSiteRequestGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            
            // Массив материалов для синхронизации
            'materials' => ['sometimes', 'array'],
            'materials.*.id' => ['nullable', 'integer', 'exists:site_requests,id'],
            'materials.*.material_id' => ['nullable', 'integer'],
            'materials.*.name' => ['required_without:materials.*.material_id', 'nullable', 'string', 'max:255'],
            'materials.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'materials.*.unit' => ['required', 'string', 'max:50'],
            'materials.*.note' => ['nullable', 'string'],
            
            // Общие поля доставки
            'delivery_address' => ['nullable', 'string'],
            'delivery_time_from' => ['nullable', 'date_format:H:i'],
            'delivery_time_to' => ['nullable', 'date_format:H:i', 'after:delivery_time_from'],
            'contact_person_name' => ['nullable', 'string', 'max:255'],
            'contact_person_phone' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'название',
            'materials.*.name' => 'название материала',
            'materials.*.quantity' => 'количество',
            'materials.*.unit' => 'единица измерения',
        ];
    }
}
