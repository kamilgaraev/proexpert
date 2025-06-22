<?php

namespace App\Http\Requests\Api\V1\Landing\OrganizationRole;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\OrganizationRole;

class StoreOrganizationRoleRequest extends FormRequest
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
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organizationId = $this->route('organization_id') ?? $this->attributes->get('current_organization_id');
        $availablePermissions = collect(OrganizationRole::getAllAvailablePermissions())->pluck('slug')->toArray();

        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'slug' => [
                'nullable',
                'string',
                'min:2',
                'max:100',
                'regex:/^[a-z0-9_-]+$/',
                function ($attribute, $value, $fail) use ($organizationId) {
                    if ($organizationId && OrganizationRole::where('organization_id', $organizationId)
                        ->where('slug', $value)
                        ->exists()) {
                        $fail('Роль с таким slug уже существует в организации.');
                    }
                }
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'in:' . implode(',', $availablePermissions)],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название роли обязательно для заполнения',
            'name.min' => 'Название роли должно содержать минимум 2 символа',
            'name.max' => 'Название роли не должно превышать 100 символов',
            'slug.regex' => 'Slug может содержать только строчные буквы, цифры, дефисы и подчеркивания',
            'permissions.required' => 'Необходимо выбрать хотя бы одно разрешение',
            'permissions.min' => 'Необходимо выбрать хотя бы одно разрешение',
            'permissions.*.in' => 'Выбрано недопустимое разрешение',
            'color.regex' => 'Цвет должен быть в формате HEX (#RRGGBB)',
            'display_order.min' => 'Порядок отображения не может быть отрицательным',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'название роли',
            'slug' => 'идентификатор роли',
            'description' => 'описание роли',
            'permissions' => 'разрешения',
            'color' => 'цвет роли',
            'is_active' => 'статус активности',
            'display_order' => 'порядок отображения',
        ];
    }
}
