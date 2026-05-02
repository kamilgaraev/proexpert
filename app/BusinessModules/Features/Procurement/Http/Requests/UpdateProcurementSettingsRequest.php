<?php

namespace App\BusinessModules\Features\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProcurementSettingsRequest extends FormRequest
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
        return [
            // Общие настройки
            'enable_notifications' => ['sometimes', 'boolean'],
            'auto_create_purchase_request' => ['sometimes', 'boolean'],
            'auto_create_invoice' => ['sometimes', 'boolean'],
            'auto_receive_to_warehouse' => ['sometimes', 'boolean'],

            // Workflow
            'require_approval' => ['sometimes', 'boolean'],
            'require_supplier_selection' => ['sometimes', 'boolean'],
            'default_currency' => ['sometimes', 'string', 'in:RUB,USD,EUR'],

            // Уведомления
            'notify_on_request_created' => ['sometimes', 'boolean'],
            'notify_on_order_sent' => ['sometimes', 'boolean'],
            'notify_on_proposal_received' => ['sometimes', 'boolean'],
            'notify_on_material_received' => ['sometimes', 'boolean'],

            // Интеграции
            'enable_site_requests_integration' => ['sometimes', 'boolean'],
            'enable_payments_integration' => ['sometimes', 'boolean'],
            'enable_warehouse_integration' => ['sometimes', 'boolean'],

            // Кеширование
            'cache_ttl' => ['sometimes', 'integer', 'min:60'],

            'approval_policy' => ['sometimes', 'array'],
            'approval_policy.non_lowest_delta_amount' => ['sometimes', 'numeric', 'min:0'],
            'approval_policy.non_lowest_delta_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'approval_policy.budget_exceed_amount' => ['sometimes', 'numeric', 'min:0'],
            'approval_policy.external_supplier_requires_identity' => ['sometimes', 'boolean'],
            'approval_policy.prevent_requester_approval' => ['sometimes', 'boolean'],
            'approval_policy.prevent_selector_approval' => ['sometimes', 'boolean'],
            'approval_policy.prevent_intake_author_approval' => ['sometimes', 'boolean'],
            'approval_policy.required_approval_permission' => ['sometimes', 'string', 'max:150'],
            'approval_policy.is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'auto_create_purchase_request' => 'автоматическое создание заявок на закупку',
            'auto_create_invoice' => 'автоматическое создание счетов',
            'auto_receive_to_warehouse' => 'автоматический прием на склад',
            'default_currency' => 'валюта по умолчанию',
        ];
    }
}
