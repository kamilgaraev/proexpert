<?php

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Enums\Contract\GpCalculationTypeEnum;
use App\DTOs\Contract\ContractDTO;
use Illuminate\Validation\Rules\Enum;
use App\Rules\ParentContractValid;

class StoreContractRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // return Auth::user()->can('create', \App\Models\Contract::class);
        return true; // Упрощенная авторизация для примера
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        // $organizationId = Auth::user()->organization_id; // или $this->route('organization')->id если есть в роуте
        return [
            'project_id' => ['nullable', 'integer', 'exists:projects,id'], // TODO: 'exists:projects,id,organization_id,'.$organizationId
            'contractor_id' => ['required', 'integer', 'exists:contractors,id'], // TODO: 'exists:contractors,id,organization_id,'.$organizationId
            'parent_contract_id' => ['nullable', 'integer', new ParentContractValid],
            'number' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date_format:Y-m-d'],
            // поле type больше не используется
            'subject' => ['nullable', 'string'],
            'work_type_category' => ['nullable', new Enum(ContractWorkTypeCategoryEnum::class)],
            'payment_terms' => ['nullable', 'string'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'gp_percentage' => ['nullable', 'numeric'],
            'gp_calculation_type' => ['nullable', new Enum(GpCalculationTypeEnum::class)],
            'gp_coefficient' => ['nullable', 'numeric'],
            'subcontract_amount' => ['nullable', 'numeric', 'min:0'],
            'planned_advance_amount' => ['nullable', 'numeric', 'min:0'],
            'actual_advance_amount' => ['nullable', 'numeric', 'min:0'],
            'advance_payments' => ['nullable', 'array'],
            'advance_payments.*.amount' => ['required', 'numeric', 'min:0'],
            'advance_payments.*.payment_date' => ['nullable', 'date_format:Y-m-d'],
            'advance_payments.*.description' => ['nullable', 'string'],
            'status' => ['required', new Enum(ContractStatusEnum::class)],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
            // Временное поле для примера в контроллере, в реальности organization_id не должен приходить из запроса на создание
            'organization_id_for_creation' => ['sometimes', 'integer'] 
        ];
    }

    public function toDto(): ContractDTO
    {
        return new ContractDTO(
            project_id: $this->validated('project_id'),
            contractor_id: $this->validated('contractor_id'),
            parent_contract_id: $this->validated('parent_contract_id'),
            number: $this->validated('number'),
            date: $this->validated('date'),
            subject: $this->validated('subject'),
            work_type_category: $this->validated('work_type_category') ? ContractWorkTypeCategoryEnum::from($this->validated('work_type_category')) : null,
            payment_terms: $this->validated('payment_terms'),
            total_amount: (float) $this->validated('total_amount'),
            gp_percentage: $this->validated('gp_percentage') !== null ? (float) $this->validated('gp_percentage') : null,
            gp_calculation_type: $this->validated('gp_calculation_type') ? GpCalculationTypeEnum::from($this->validated('gp_calculation_type')) : null,
            gp_coefficient: $this->validated('gp_coefficient') !== null ? (float) $this->validated('gp_coefficient') : null,
            subcontract_amount: $this->validated('subcontract_amount') !== null ? (float) $this->validated('subcontract_amount') : null,
            planned_advance_amount: $this->validated('planned_advance_amount') !== null ? (float) $this->validated('planned_advance_amount') : null,
            actual_advance_amount: $this->validated('actual_advance_amount') !== null ? (float) $this->validated('actual_advance_amount') : null,
            status: ContractStatusEnum::from($this->validated('status')),
            start_date: $this->validated('start_date'),
            end_date: $this->validated('end_date'),
            notes: $this->validated('notes'),
            advance_payments: $this->validated('advance_payments')
        );
    }
} 