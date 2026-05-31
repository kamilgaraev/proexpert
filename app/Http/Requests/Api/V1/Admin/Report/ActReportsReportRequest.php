<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Report;

use App\Models\ContractPerformanceAct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

use function trans_message;

class ActReportsReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->attributes->get('current_organization_id') ?? $this->user()->current_organization_id;

        return [
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId),
            ],
            'contractor_id' => [
                'nullable',
                'integer',
                Rule::exists('contractors', 'id')->where('organization_id', $organizationId),
            ],
            'contract_id' => [
                'nullable',
                'integer',
                Rule::exists('contracts', 'id')->where('organization_id', $organizationId),
            ],
            'status' => [
                'nullable',
                Rule::in([
                    ContractPerformanceAct::STATUS_DRAFT,
                    ContractPerformanceAct::STATUS_PENDING_APPROVAL,
                    ContractPerformanceAct::STATUS_APPROVED,
                    ContractPerformanceAct::STATUS_REJECTED,
                    ContractPerformanceAct::STATUS_SIGNED,
                ]),
            ],
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'search' => 'nullable|string|max:255',
            'format' => 'nullable|string|in:json,excel,pdf',
        ];
    }

    public function messages(): array
    {
        return [
            'project_id.exists' => trans_message('reports.validation.project_not_found'),
            'contractor_id.exists' => trans_message('reports.validation.contractor_not_found'),
            'contract_id.exists' => trans_message('reports.validation.contract_not_found'),
            'status.in' => trans_message('reports.validation.act_status_invalid'),
            'date_to.after_or_equal' => trans_message('reports.validation.date_to_after_or_equal'),
            'format.in' => trans_message('reports.validation.format_invalid'),
        ];
    }
}
