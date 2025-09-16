<?php

namespace App\Http\Requests\Api\V1\Admin\AdvanceSetting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\Organization;

class StoreOrUpdateAdvanceSettingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Проверяем, что пользователь имеет права на управление настройками организации
        // Это может быть проверка роли администратора организации или специфического права
        $organizationId = Auth::user()->current_organization_id;
        if (!$organizationId) {
            return false; // Нет контекста организации
        }
        // Пример: предполагаем, что у User есть метод canManageOrganizationSettings
        // return Auth::user()->canManageOrganizationSettings($organizationId);
        // Пока что просто разрешаем, если есть доступ к админ-панели и текущая организация.
        // Авторизация проверяется на уровне middleware
        return Auth::check(); 
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'max_single_issue_amount' => 'nullable|numeric|min:0|max:999999999.99',
            'report_submission_deadline_days' => 'nullable|integer|min:0|max:365',
            'dual_authorization_threshold' => 'nullable|numeric|min:0|max:999999999.99',
            'require_project_for_expense' => 'present|boolean',
            'notify_admin_on_overdue_report' => 'present|boolean',
            'notify_admin_on_high_value_transaction' => 'present|boolean',
            'high_value_notification_threshold' => 'nullable|numeric|min:0|max:999999999.99|required_if:notify_admin_on_high_value_transaction,true',
            'notify_user_on_transaction_approval' => 'present|boolean',
            'notify_user_on_transaction_rejection' => 'present|boolean',
            'send_report_reminder_days_before' => 'nullable|integer|min:0|max:90',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'max_single_issue_amount' => 'максимальная сумма одной транзакции на выдачу',
            'report_submission_deadline_days' => 'срок предоставления отчета (дни)',
            'dual_authorization_threshold' => 'порог двойной авторизации',
            'require_project_for_expense' => 'требовать проект для расходов',
            'notify_admin_on_overdue_report' => 'уведомлять администратора о просроченных отчетах',
            'notify_admin_on_high_value_transaction' => 'уведомлять администратора о крупных транзакциях',
            'high_value_notification_threshold' => 'порог для уведомления о крупной транзакции',
            'notify_user_on_transaction_approval' => 'уведомлять пользователя об утверждении транзакции',
            'notify_user_on_transaction_rejection' => 'уведомлять пользователя об отклонении транзакции',
            'send_report_reminder_days_before' => 'отправлять напоминание об отчете за (дни)',
        ];
    }
} 