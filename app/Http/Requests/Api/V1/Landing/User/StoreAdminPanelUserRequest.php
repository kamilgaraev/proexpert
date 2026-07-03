<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Landing\User;

use App\Helpers\AdminPanelAccessHelper;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

use function trans_message;

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

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => Str::lower(trim((string) $this->input('email'))),
            ]);
        }
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
        $allowedRoles = $this->adminPanelHelper->getAdminPanelRoles(
            $organizationId ? (int) $organizationId : null,
            (string) $currentInterface,
            true
        );
        
        Log::info('[StoreAdminPanelUserRequest] Validating role', [
            'organization_id' => $organizationId,
            'current_interface' => $currentInterface,
            'current_user_id' => $this->user()?->id,
            'requested_role' => $this->input('role_slug'),
            'allowed_roles' => $allowedRoles,
            'request_data' => $this->all()
        ]);

        return [
            'name' => 'required|string|max:255',
            'email' => [
                'bail',
                'required',
                'string',
                'email',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $email = Str::lower(trim((string) $value));

                    if (
                        DB::table('users')
                            ->whereRaw('LOWER(email) = ?', [$email])
                            ->whereNull('deleted_at')
                            ->exists()
                    ) {
                        $fail(trans_message('landing_users.admin_panel_email_exists'));
                    }
                },
            ],
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
        return [
            'role_slug.required' => trans_message('landing_users.validation.role_required'),
            'role_slug.in' => trans_message('landing_users.validation.role_invalid'),
            'email.email' => trans_message('landing_users.validation.email_invalid'),
            'email.required' => trans_message('landing_users.validation.email_required'),
            'password.confirmed' => trans_message('landing_users.validation.password_confirmed'),
            'password.min' => trans_message('landing_users.validation.password_min'),
        ];
    }
}
