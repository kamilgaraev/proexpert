<?php

namespace App\Http\Requests\Api\V1\Landing;

use Illuminate\Foundation\Http\FormRequest;

class CreateChildOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group_id' => 'required|integer|exists:organization_groups,id',
            
            'organization.name' => 'required|string|min:2|max:255',
            'organization.legal_name' => 'nullable|string|min:2|max:255',
            'organization.tax_number' => 'nullable|string|size:10|unique:organizations,tax_number',
            'organization.registration_number' => 'nullable|string|min:13|max:15|unique:organizations,registration_number',
            'organization.phone' => 'nullable|string|regex:/^\+7\(\d{3}\)\d{3}-\d{2}-\d{2}$/',
            'organization.email' => 'nullable|email|max:255|unique:organizations,email',
            'organization.address' => 'nullable|string|min:10|max:500',
            'organization.city' => 'nullable|string|min:2|max:100|regex:/^[а-яёА-ЯЁa-zA-Z\s\-]+$/u',
            'organization.postal_code' => 'nullable|string|size:6|regex:/^\d{6}$/',
            'organization.country' => 'nullable|string|min:2|max:100',
            'organization.description' => 'nullable|string|max:1000',
            
            'admin.type' => 'required|in:new,existing,assign',
            'admin.name' => 'required_if:admin.type,new|string|min:2|max:255',
            'admin.email' => 'required_if:admin.type,new,existing|email|max:255',
            'admin.user_id' => 'required_if:admin.type,assign|integer|exists:users,id',
            'admin.phone' => 'nullable|string|regex:/^\+7\(\d{3}\)\d{3}-\d{2}-\d{2}$/',
            'admin.send_notification' => 'boolean',
            
            'modules' => 'array',
            'modules.*' => 'string|exists:organization_modules,slug',
            
            'permissions' => 'array',
            'permissions.projects' => 'array',
            'permissions.projects.*' => 'in:read,create,edit,delete',
            'permissions.contracts' => 'array',
            'permissions.contracts.*' => 'in:read,create,edit,delete',
            'permissions.materials' => 'array',
            'permissions.materials.*' => 'in:read,create,edit,delete',
            'permissions.reports' => 'array',
            'permissions.reports.*' => 'in:read,export,admin',
            'permissions.users' => 'array',
            'permissions.users.*' => 'in:read,create,edit',
        ];
    }

    public function messages(): array
    {
        return [
            'organization.name.required' => 'Название организации обязательно',
            'organization.tax_number.size' => 'ИНН должен содержать 10 цифр',
            'organization.tax_number.unique' => 'Организация с таким ИНН уже существует',
            'organization.registration_number.unique' => 'Организация с таким ОГРН уже существует',
            'organization.phone.regex' => 'Номер телефона должен быть в формате +7(XXX)XXX-XX-XX',
            'organization.email.unique' => 'Организация с таким email уже существует',
            'organization.postal_code.regex' => 'Почтовый индекс должен содержать 6 цифр',
            'admin.type.required' => 'Необходимо выбрать тип администратора',
            'admin.name.required_if' => 'Имя администратора обязательно при создании нового пользователя',
            'admin.email.required_if' => 'Email администратора обязателен',
            'admin.user_id.required_if' => 'Необходимо выбрать пользователя для назначения',
        ];
    }
} 