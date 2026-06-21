<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\PresaleEstimates\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

use function trans_message;

class PresaleBudgetTransferRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'source' => ['nullable', 'array'],
            'source.source_type' => ['nullable', Rule::in(['presale_estimate', 'commercial_proposal', 'tender', 'crm_deal'])],
            'source.source_id' => ['nullable', 'uuid'],
            'presale_estimate_id' => ['nullable', 'uuid'],
            'commercial_proposal_id' => ['nullable', 'uuid'],
            'tender_id' => ['nullable', 'uuid'],
            'crm_deal_id' => ['nullable', 'uuid'],
            'target' => ['nullable', 'array'],
            'target.project_id' => ['nullable', 'integer'],
            'target.contract_id' => ['nullable', 'integer'],
            'target.budget_version_id' => ['nullable', 'uuid'],
            'target.default_month' => ['nullable', 'date_format:Y-m'],
            'target.create_budget_version' => ['nullable', 'array'],
            'target.create_budget_version.budget_period_id' => ['nullable', 'uuid'],
            'target.create_budget_version.scenario_id' => ['nullable', 'uuid'],
            'target.create_budget_version.budget_kind' => ['nullable', Rule::in(['bdr', 'bdds', 'consolidated'])],
            'target.create_budget_version.name' => ['nullable', 'string', 'max:255'],
            'target.create_budget_version.description' => ['nullable', 'string', 'max:2000'],
            'mapping' => ['nullable', 'array'],
            'mapping.default_budget_article_id' => ['nullable', 'uuid'],
            'mapping.default_responsibility_center_id' => ['nullable', 'uuid'],
            'mapping.rows' => ['nullable', 'array'],
            'mapping.rows.*.source_row_id' => ['required_with:mapping.rows', 'string', 'max:128'],
            'mapping.rows.*.included' => ['nullable', 'boolean'],
            'mapping.rows.*.budget_article_id' => ['nullable', 'uuid'],
            'mapping.rows.*.responsibility_center_id' => ['nullable', 'uuid'],
            'mapping.rows.*.month' => ['nullable', 'date_format:Y-m'],
            'mapping.rows.*.plan_amount' => ['nullable', 'numeric', 'min:0'],
            'mapping.rows.*.forecast_amount' => ['nullable', 'numeric', 'min:0'],
            'preview_hash' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function messages(): array
    {
        return [
            'source.source_type.in' => trans_message('presale_estimates.budget_transfer.validation.source_type_invalid'),
            'source.source_id.uuid' => trans_message('presale_estimates.budget_transfer.validation.source_id_invalid'),
            '*.uuid' => trans_message('presale_estimates.budget_transfer.validation.uuid_invalid'),
            'target.project_id.integer' => trans_message('presale_estimates.budget_transfer.validation.project_invalid'),
            'target.contract_id.integer' => trans_message('presale_estimates.budget_transfer.validation.contract_invalid'),
            'target.default_month.date_format' => trans_message('presale_estimates.budget_transfer.validation.month_invalid'),
            'target.create_budget_version.budget_kind.in' => trans_message('presale_estimates.budget_transfer.validation.budget_kind_invalid'),
            'mapping.rows.*.source_row_id.required_with' => trans_message('presale_estimates.budget_transfer.validation.source_row_required'),
            'mapping.rows.*.month.date_format' => trans_message('presale_estimates.budget_transfer.validation.month_invalid'),
            'mapping.rows.*.plan_amount.numeric' => trans_message('presale_estimates.budget_transfer.validation.amount_invalid'),
            'mapping.rows.*.forecast_amount.numeric' => trans_message('presale_estimates.budget_transfer.validation.amount_invalid'),
        ];
    }
}
