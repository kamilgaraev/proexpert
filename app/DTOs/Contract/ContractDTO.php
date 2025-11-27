<?php

namespace App\DTOs\Contract;

use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Enums\Contract\GpCalculationTypeEnum;
use Illuminate\Http\Request; // Для гидрации из Request, если нужно

class ContractDTO
{
    public function __construct(
        public readonly ?int $project_id,
        public readonly ?int $contractor_id,
        public readonly ?int $parent_contract_id,
        public readonly string $number,
        public readonly string $date, // Y-m-d format
        public readonly ?string $subject,
        public readonly ?ContractWorkTypeCategoryEnum $work_type_category,
        public readonly ?string $payment_terms,
        public readonly ?float $base_amount, // Базовая сумма ДО учета ГП
        public readonly float $total_amount, // Итоговая сумма (может быть с учетом ГП)
        public readonly ?float $gp_percentage,
        public readonly ?GpCalculationTypeEnum $gp_calculation_type,
        public readonly ?float $gp_coefficient,
        public readonly ?GpCalculationTypeEnum $warranty_retention_calculation_type,
        public readonly ?float $warranty_retention_percentage,
        public readonly ?float $warranty_retention_coefficient,
        public readonly ?float $subcontract_amount,
        public readonly ?float $planned_advance_amount,
        public readonly ?float $actual_advance_amount,
        public readonly ContractStatusEnum $status,
        public readonly ?string $start_date, // Y-m-d format
        public readonly ?string $end_date, // Y-m-d format
        public readonly ?string $notes,
        public readonly ?array $advance_payments = null
    ) {}

    public function toArray(): array
    {
        return [
            'project_id' => $this->project_id,
            'contractor_id' => $this->contractor_id,
            'parent_contract_id' => $this->parent_contract_id,
            'number' => $this->number,
            'date' => $this->date,
            'subject' => $this->subject,
            'work_type_category' => $this->work_type_category?->value,
            'payment_terms' => $this->payment_terms,
            'base_amount' => $this->base_amount,
            'total_amount' => $this->total_amount,
            'gp_percentage' => $this->gp_percentage,
            'gp_calculation_type' => $this->gp_calculation_type?->value,
            'gp_coefficient' => $this->gp_coefficient,
            'warranty_retention_calculation_type' => $this->warranty_retention_calculation_type?->value,
            'warranty_retention_percentage' => $this->warranty_retention_percentage,
            'warranty_retention_coefficient' => $this->warranty_retention_coefficient,
            'subcontract_amount' => $this->subcontract_amount,
            'planned_advance_amount' => $this->planned_advance_amount,
            'actual_advance_amount' => $this->actual_advance_amount,
            'status' => $this->status->value,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'notes' => $this->notes,
        ];
    }

    // Опционально: статический метод для создания из Request
    /*
    public static function fromRequest(Request $request): self
    {
        return new self(
            project_id: $request->input('project_id'),
            contractor_id: $request->input('contractor_id'),
            parent_contract_id: $request->input('parent_contract_id'),
            number: $request->input('number'),
            date: $request->input('date'),
            subject: $request->input('subject'),
            work_type_category: $request->input('work_type_category') ? ContractWorkTypeCategoryEnum::from($request->input('work_type_category')) : null,
            payment_terms: $request->input('payment_terms'),
            total_amount: (float) $request->input('total_amount'),
            gp_percentage: $request->input('gp_percentage') ? (float) $request->input('gp_percentage') : null,
            planned_advance_amount: $request->input('planned_advance_amount') ? (float) $request->input('planned_advance_amount') : null,
            status: ContractStatusEnum::from($request->input('status')),
            start_date: $request->input('start_date'),
            end_date: $request->input('end_date'),
            notes: $request->input('notes')
        );
    }
    */
} 