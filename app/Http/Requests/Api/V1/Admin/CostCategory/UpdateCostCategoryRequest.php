<?php

namespace App\Http\Requests\Api\V1\Admin\CostCategory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCostCategoryRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes', 
                'nullable', 
                'string', 
                'max:100',
                Rule::unique('cost_categories', 'code')
                    ->where('organization_id', $this->user()->current_organization_id)
                    ->whereNull('deleted_at')
                    ->ignore($this->route('cost_category'))
            ],
            'external_code' => [
                'sometimes', 
                'nullable', 
                'string', 
                'max:100',
                Rule::unique('cost_categories', 'external_code')
                    ->where('organization_id', $this->user()->current_organization_id)
                    ->whereNull('deleted_at')
                    ->ignore($this->route('cost_category'))
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'parent_id' => [
                'sometimes', 
                'nullable', 
                'integer', 
                'exists:cost_categories,id',
                function ($attribute, $value, $fail) {
                    // Проверка, что категория не устанавливается родителем самой себя
                    if ($value == $this->route('cost_category')) {
                        $fail('Категория не может быть родителем самой себя.');
                    }
                }
            ],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
            'additional_attributes' => ['sometimes', 'nullable', 'array'],
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
