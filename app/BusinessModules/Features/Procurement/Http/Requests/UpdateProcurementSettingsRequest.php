<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Requests;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProcurementSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $organizationId = $this->attributes->get('current_organization_id');

        if (!$user instanceof User || !$organizationId) {
            return false;
        }

        return app(AuthorizationService::class)->can(
            $user,
            'procurement.settings.manage',
            ['organization_id' => (int) $organizationId]
        );
    }

    public function rules(): array
    {
        return [
            'enable_notifications' => ['sometimes', 'boolean'],
            'auto_create_purchase_request' => ['sometimes', 'boolean'],
            'require_material_fulfillment_decision_before_purchase' => ['sometimes', 'boolean'],
            'auto_create_invoice' => ['sometimes', 'boolean'],
            'auto_receive_to_warehouse' => ['sometimes', 'boolean'],
            'require_approval' => ['sometimes', 'boolean'],
            'require_supplier_selection' => ['sometimes', 'boolean'],
            'default_currency' => ['sometimes', 'string', 'in:RUB,USD,EUR'],
            'notify_on_request_created' => ['sometimes', 'boolean'],
            'notify_on_order_sent' => ['sometimes', 'boolean'],
            'notify_on_proposal_received' => ['sometimes', 'boolean'],
            'notify_on_material_received' => ['sometimes', 'boolean'],
            'enable_site_requests_integration' => ['sometimes', 'boolean'],
            'enable_payments_integration' => ['sometimes', 'boolean'],
            'enable_warehouse_integration' => ['sometimes', 'boolean'],
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
