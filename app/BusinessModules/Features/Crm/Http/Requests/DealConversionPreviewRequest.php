<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

use function trans_message;

class DealConversionPreviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tender_id' => ['nullable', 'uuid'],
            'commercial_proposal_id' => ['nullable', 'uuid'],
            'project' => ['nullable', 'array'],
            'project.mode' => ['nullable', Rule::in(['create', 'reuse'])],
            'project.id' => ['nullable', 'integer'],
            'project.fields' => ['nullable', 'array'],
            'project.fields.name' => ['nullable', 'string', 'max:255'],
            'project.fields.description' => ['nullable', 'string', 'max:5000'],
            'project.fields.customer' => ['nullable', 'string', 'max:255'],
            'project.fields.address' => ['nullable', 'string', 'max:1000'],
            'project.fields.start_date' => ['nullable', 'date'],
            'project.fields.end_date' => ['nullable', 'date', 'after_or_equal:project.fields.start_date'],
            'project.fields.status' => ['nullable', Rule::in(['draft', 'active', 'completed', 'paused', 'cancelled'])],
            'project.fields.budget_amount' => ['nullable', 'numeric', 'min:0'],
            'project.fields.contract_number' => ['nullable', 'string', 'max:255'],
            'project.fields.cost_category_id' => ['nullable', 'integer'],
            'contract' => ['nullable', 'array'],
            'contract.mode' => ['nullable', Rule::in(['create', 'reuse'])],
            'contract.id' => ['nullable', 'integer'],
            'contract.fields' => ['nullable', 'array'],
            'contract.fields.number' => ['nullable', 'string', 'max:255'],
            'contract.fields.date' => ['nullable', 'date'],
            'contract.fields.subject' => ['nullable', 'string', 'max:2000'],
            'contract.fields.status' => ['nullable', Rule::in(['draft', 'active', 'completed', 'on_hold', 'terminated'])],
            'contract.fields.contract_side_type' => ['nullable', Rule::in([
                'customer_to_general_contractor',
                'general_contractor_to_contractor',
                'general_contractor_to_supplier',
                'contractor_to_subcontractor',
                'contractor_to_supplier',
                'subcontractor_to_supplier',
            ])],
            'contract.fields.base_amount' => ['nullable', 'numeric', 'min:0'],
            'contract.fields.total_amount' => ['nullable', 'numeric', 'min:0'],
            'contract.fields.start_date' => ['nullable', 'date'],
            'contract.fields.end_date' => ['nullable', 'date', 'after_or_equal:contract.fields.start_date'],
            'contract.fields.notes' => ['nullable', 'string', 'max:5000'],
            'contract.fields.is_fixed_amount' => ['nullable', 'boolean'],
            'counterparty' => ['nullable', 'array'],
            'counterparty.contractor_id' => ['nullable', 'integer'],
            'counterparty.supplier_id' => ['nullable', 'integer'],
            'budget_seed' => ['nullable', 'array'],
            'budget_seed.accepted' => ['nullable', 'boolean'],
            'preview_hash' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function messages(): array
    {
        return [
            'tender_id.uuid' => trans_message('crm.conversion.validation.tender_invalid'),
            'commercial_proposal_id.uuid' => trans_message('crm.conversion.validation.commercial_proposal_invalid'),
            'project.mode.in' => trans_message('crm.conversion.validation.project_mode_invalid'),
            'project.id.integer' => trans_message('crm.conversion.validation.project_invalid'),
            'project.fields.name.max' => trans_message('crm.conversion.validation.project_name_too_long'),
            'project.fields.end_date.after_or_equal' => trans_message('crm.conversion.validation.project_dates_invalid'),
            'project.fields.status.in' => trans_message('crm.conversion.validation.project_status_invalid'),
            'contract.mode.in' => trans_message('crm.conversion.validation.contract_mode_invalid'),
            'contract.id.integer' => trans_message('crm.conversion.validation.contract_invalid'),
            'contract.fields.number.max' => trans_message('crm.conversion.validation.contract_number_too_long'),
            'contract.fields.status.in' => trans_message('crm.conversion.validation.contract_status_invalid'),
            'contract.fields.contract_side_type.in' => trans_message('crm.conversion.validation.contract_side_invalid'),
            'contract.fields.end_date.after_or_equal' => trans_message('crm.conversion.validation.contract_dates_invalid'),
            'counterparty.contractor_id.integer' => trans_message('crm.conversion.validation.contractor_invalid'),
            'counterparty.supplier_id.integer' => trans_message('crm.conversion.validation.supplier_invalid'),
        ];
    }
}
