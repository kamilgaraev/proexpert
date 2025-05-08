<?php

namespace App\Http\Requests\Api\V1\Admin\CostCategory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCostCategoryRequest extends FormRequest
{
    /**
     * Определить, авторизован ли пользователь для выполнения этого запроса.
     */
    public function authorize(): bool
    {
        return true; // Проверка через middleware
    }

    /**
     * Получить правила валидации для этого запроса.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable', 
                'string', 
                'max:100',
                Rule::unique('cost_categories', 'code')
                    ->where('organization_id', $this->user()->current_organization_id)
                    ->whereNull('deleted_at')
            ],
            'external_code' => [
                'nullable', 
                'string', 
                'max:100',
                Rule::unique('cost_categories', 'external_code')
                    ->where('organization_id', $this->user()->current_organization_id)
                    ->whereNull('deleted_at')
            ],
            'description' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'integer', 'exists:cost_categories,id'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
            'additional_attributes' => ['nullable', 'array'],
        ];
    }

    /**
     * Получить пользовательские сообщения об ошибках для определенных правил валидации.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Наименование категории затрат обязательно для заполнения.',
            'name.max' => 'Наименование категории затрат не должно превышать 255 символов.',
            'code.unique' => 'Категория затрат с таким кодом уже существует в вашей организации.',
            'external_code.unique' => 'Категория затрат с таким внешним кодом уже существует в вашей организации.',
            'parent_id.exists' => 'Указанная родительская категория не существует.',
        ];
    }
}
