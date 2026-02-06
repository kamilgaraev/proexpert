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
