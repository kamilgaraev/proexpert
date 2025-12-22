<?php

namespace App\Http\Requests\Api\V1\Landing\User;

use App\Helpers\AdminPanelAccessHelper;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreAdminPanelUserRequest extends FormRequest
{
    protected AdminPanelAccessHelper $adminPanelHelper;

    public function __construct(AdminPanelAccessHelper $adminPanelHelper)
    {
        parent::__construct();
        $this->adminPanelHelper = $adminPanelHelper;
    }

    /**
     * Determine if the user is authorized to make this request.
     * Авторизация уже проверена middleware, здесь только базовая проверка.
     */
    public function authorize(): bool
    {
        return Auth::check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organizationId = $this->route('organization_id') ?? $this->user()?->current_organization_id;
        $currentInterface = $this->input('current_interface', 'lk'); // Из middleware или параметра
        $allowedRoles = $this->adminPanelHelper->getAdminPanelRoles($organizationId, $currentInterface);
        
        \Illuminate\Support\Facades\Log::info('[StoreAdminPanelUserRequest] Validating role', [
            'organization_id' => $organizationId,
            'current_interface' => $currentInterface,
            'current_user_id' => $this->user()?->id,
            'requested_role' => $this->input('role_slug'),
            'allowed_roles' => $allowedRoles,
            'request_data' => $this->all()
        ]);

        return [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8|confirmed',
            'role_slug' => [
                'required',
                'string',
                Rule::in($allowedRoles),
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        $organizationId = $this->route('organization_id') ?? $this->user()?->current_organization_id;
        $currentInterface = $this->input('current_interface', 'lk');
        $allowedRoles = $this->adminPanelHelper->getAdminPanelRoles($organizationId, $currentInterface);

        return [
            'role_slug.required' => 'Необходимо указать роль пользователя.',
            'role_slug.in' => 'Выбрана недопустимая роль. Разрешенные роли: ' . implode(', ', $allowedRoles) . '.',
        ];
    }
} 