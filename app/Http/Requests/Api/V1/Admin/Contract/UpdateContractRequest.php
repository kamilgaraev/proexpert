<?php

namespace App\Http\Requests\Api\V1\Admin\Contract;

// Копируем большую часть из StoreContractRequest, но делаем поля менее строгими (например, не все required)
// или адаптируем правила для обновления.

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Enums\Contract\GpCalculationTypeEnum;
use App\DTOs\Contract\ContractDTO;
use Illuminate\Validation\Rules\Enum;
use App\Rules\ParentContractValid;

class UpdateContractRequest extends FormRequest // Был StoreContractRequest
{
    public function authorize(): bool
    {
        // $contract = $this->route('contract'); // Если используем Route Model Binding
        // return Auth::user()->can('update', $contract);
        return true; 
    }

    public function rules(): array
    {
        return [
            'project_id' => ['sometimes', 'nullable', 'integer', 'exists:projects,id'],
            'contractor_id' => ['sometimes', 'nullable', 'integer', 'exists:contractors,id'],
            'parent_contract_id' => ['sometimes', 'nullable', 'integer', new ParentContractValid], 
            'number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'date' => ['sometimes', 'nullable', 'date'],
            'subject' => ['sometimes', 'nullable', 'string'],
            'work_type_category' => ['sometimes', 'nullable', new Enum(ContractWorkTypeCategoryEnum::class)],
            'payment_terms' => ['sometimes', 'nullable', 'string'],
            'base_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'total_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'gp_percentage' => ['sometimes', 'nullable', 'numeric', 'min:-100', 'max:100'],
            'gp_calculation_type' => ['sometimes', 'nullable', new Enum(GpCalculationTypeEnum::class)],
            'gp_coefficient' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'subcontract_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'planned_advance_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'actual_advance_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'nullable', new Enum(ContractStatusEnum::class)],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
    
    protected function prepareForValidation()
    {
        $input = $this->all();
        
        Log::info('UpdateContractRequest::prepareForValidation - RAW INPUT', [
            'contract_id' => $this->route('contract'),
            'input_keys' => array_keys($input),
            'base_amount' => $input['base_amount'] ?? 'NOT SET',
            'total_amount' => $input['total_amount'] ?? 'NOT SET',
            'has_agreements' => isset($input['agreements']),
            'has_specifications' => isset($input['specifications']),
            'has_payments' => isset($input['payments']),
            'input_count' => count($input)
        ]);
        
        $allowedFields = [
            'project_id', 'contractor_id', 'parent_contract_id', 'number', 'date',
            'subject', 'work_type_category', 'payment_terms', 'base_amount', 'total_amount',
            'gp_percentage', 'gp_calculation_type', 'gp_coefficient', 'subcontract_amount',
            'planned_advance_amount', 'actual_advance_amount', 'status', 'start_date',
            'end_date', 'notes'
        ];
        
        $filtered = array_intersect_key($input, array_flip($allowedFields));
        
        Log::info('UpdateContractRequest::prepareForValidation - FILTERED', [
            'contract_id' => $this->route('contract'),
            'filtered_keys' => array_keys($filtered),
            'base_amount' => $filtered['base_amount'] ?? 'NOT SET',
            'total_amount' => $filtered['total_amount'] ?? 'NOT SET',
            'filtered_count' => count($filtered)
        ]);
        
        $this->replace($filtered);
    }

    public function toDto(): ContractDTO
    {
        $contractId = $this->route('contract');
        
        $contract = \App\Models\Contract::findOrFail($contractId);
        
        Log::info('UpdateContractRequest::toDto - EXISTING CONTRACT', [
            'contract_id' => $contractId,
            'current_total_amount' => $contract->total_amount,
            'current_base_amount' => $contract->total_amount
        ]);
        
        $validatedData = $this->validated();
        
        Log::info('UpdateContractRequest::toDto - VALIDATED DATA', [
            'contract_id' => $contractId,
            'validated_keys' => array_keys($validatedData),
            'base_amount' => $validatedData['base_amount'] ?? 'NOT SET',
            'total_amount' => $validatedData['total_amount'] ?? 'NOT SET'
        ]);

        return new ContractDTO(
            project_id: $validatedData['project_id'] ?? $contract->project_id,
            contractor_id: $validatedData['contractor_id'] ?? $contract->contractor_id,
            parent_contract_id: array_key_exists('parent_contract_id', $validatedData) 
                ? $validatedData['parent_contract_id'] 
                : $contract->parent_contract_id,
            number: $validatedData['number'] ?? $contract->number,
            date: isset($validatedData['date']) 
                ? (\Carbon\Carbon::parse($validatedData['date'])->format('Y-m-d'))
                : ($contract->date ? $contract->date->format('Y-m-d') : null),
            subject: array_key_exists('subject', $validatedData) 
                ? $validatedData['subject'] 
                : $contract->subject,
            work_type_category: isset($validatedData['work_type_category']) 
                ? ContractWorkTypeCategoryEnum::from($validatedData['work_type_category']) 
                : $contract->work_type_category,
            payment_terms: array_key_exists('payment_terms', $validatedData) 
                ? $validatedData['payment_terms'] 
                : $contract->payment_terms,
            total_amount: (function() use ($validatedData, $contract, $contractId) {
                $result = isset($validatedData['base_amount']) 
                    ? (float) $validatedData['base_amount'] 
                    : (isset($validatedData['total_amount']) 
                        ? (float) $validatedData['total_amount'] 
                        : (float) $contract->total_amount);
                
                Log::info('UpdateContractRequest::toDto - TOTAL_AMOUNT CALCULATION', [
                    'contract_id' => $contractId,
                    'has_base_amount' => isset($validatedData['base_amount']),
                    'base_amount_value' => $validatedData['base_amount'] ?? 'NOT SET',
                    'has_total_amount' => isset($validatedData['total_amount']),
                    'total_amount_value' => $validatedData['total_amount'] ?? 'NOT SET',
                    'current_contract_total_amount' => $contract->total_amount,
                    'result_total_amount' => $result
                ]);
                
                return $result;
            })(),
            gp_percentage: array_key_exists('gp_percentage', $validatedData)
                ? ($validatedData['gp_percentage'] !== null ? (float) $validatedData['gp_percentage'] : null)
                : $contract->gp_percentage,
            gp_calculation_type: array_key_exists('gp_calculation_type', $validatedData)
                ? ($validatedData['gp_calculation_type'] ? GpCalculationTypeEnum::from($validatedData['gp_calculation_type']) : null)
                : $contract->gp_calculation_type,
            gp_coefficient: array_key_exists('gp_coefficient', $validatedData)
                ? ($validatedData['gp_coefficient'] !== null ? (float) $validatedData['gp_coefficient'] : null)
                : $contract->gp_coefficient,
            subcontract_amount: array_key_exists('subcontract_amount', $validatedData)
                ? ($validatedData['subcontract_amount'] !== null ? (float) $validatedData['subcontract_amount'] : null)
                : $contract->subcontract_amount,
            planned_advance_amount: array_key_exists('planned_advance_amount', $validatedData)
                ? ($validatedData['planned_advance_amount'] !== null ? (float) $validatedData['planned_advance_amount'] : null)
                : $contract->planned_advance_amount,
            actual_advance_amount: array_key_exists('actual_advance_amount', $validatedData) 
                ? ($validatedData['actual_advance_amount'] !== null ? (float) $validatedData['actual_advance_amount'] : null)
                : $contract->actual_advance_amount,
            status: isset($validatedData['status']) 
                ? ContractStatusEnum::from($validatedData['status']) 
                : $contract->status,
            start_date: array_key_exists('start_date', $validatedData) 
                ? (\Carbon\Carbon::parse($validatedData['start_date'])->format('Y-m-d'))
                : ($contract->start_date ? $contract->start_date->format('Y-m-d') : null),
            end_date: array_key_exists('end_date', $validatedData) 
                ? (\Carbon\Carbon::parse($validatedData['end_date'])->format('Y-m-d'))
                : ($contract->end_date ? $contract->end_date->format('Y-m-d') : null),
            notes: array_key_exists('notes', $validatedData) 
                ? $validatedData['notes'] 
                : $contract->notes,
            advance_payments: null
        );
    }
} 