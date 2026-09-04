<?php

namespace App\Http\Requests\Api\V1\Landing\UserInvitation;

use App\Helpers\AdminPanelAccessHelper;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserInvitationRequest extends FormRequest
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
        return [
            'email' => [
                'required',
                'email:rfc,dns',
                'max:255',
                function ($attribute, $value, $fail) {
                    $organizationId = $this->attributes->get('current_organization_id');
                    if ($organizationId) {
                        $existingUser = \App\Models\User::where('email', $value)->first();
                        if ($existingUser && $existingUser->belongsToOrganization($organizationId)) {
                            $fail('Пользователь с таким email уже состоит в организации.');
                        }
                        
                        $pendingInvitation = \App\Models\UserInvitation::where('email', $value)
                            ->where('organization_id', $organizationId)
                            ->where('status', 'pending')
                            ->where('expires_at', '>', now())
                            ->exists();
                        if ($pendingInvitation) {
                            $fail('Активное приглашение для этого email уже существует.');
                        }
                    }
                }
            ],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'role_slugs' => ['nullable', 'array', 'required_without:custom_role_ids'],
            'role_slugs.*' => [
                'string',
                Rule::in(app(AdminPanelAccessHelper::class)->getAdminPanelRoles(null, 'lk', true)),
            ],
            'custom_role_ids' => ['nullable', 'array', 'required_without:role_slugs'],
            'custom_role_ids.*' => ['integer', 'min:1', 'distinct:strict'],
            'metadata' => ['nullable', 'array'],
            'metadata.welcome_message' => ['nullable', 'string', 'max:1000'],
            'metadata.department' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Email адрес обязателен для заполнения',
            'email.email' => 'Введите корректный email адрес',
            'email.max' => 'Email адрес не должен превышать 255 символов',
            'name.required' => 'Имя пользователя обязательно для заполнения',
            'name.min' => 'Имя должно содержать минимум 2 символа',
            'name.max' => 'Имя не должно превышать 100 символов',
            'role_slugs.required' => 'Необходимо выбрать хотя бы одну роль',
            'role_slugs.min' => 'Необходимо выбрать хотя бы одну роль',
            'role_slugs.required_without' => 'Необходимо выбрать хотя бы одну роль',
            'custom_role_ids.required_without' => 'Необходимо выбрать хотя бы одну роль',
            'custom_role_ids.*.integer' => 'Выбрана недопустимая роль',
            'custom_role_ids.*.distinct' => 'Одна роль выбрана несколько раз',
            'role_slugs.*.in' => 'Выбрана недопустимая роль',
            'metadata.welcome_message.max' => 'Приветственное сообщение не должно превышать 1000 символов',
            'metadata.department.max' => 'Название отдела не должно превышать 100 символов',
        ];
    }

    public function attributes(): array
    {
        return [
            'email' => 'email адрес',
            'name' => 'имя пользователя',
            'role_slugs' => 'готовые роли',
            'custom_role_ids' => 'роли компании',
            'metadata.welcome_message' => 'приветственное сообщение',
            'metadata.department' => 'отдел',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => strtolower(trim($this->email)),
            ]);
        }
    }
}
