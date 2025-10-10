<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Contracts;

use App\Repositories\ContractRepository;

class GetContractDetailsAction
{
    protected ContractRepository $contractRepository;

    public function __construct(ContractRepository $contractRepository)
    {
        $this->contractRepository = $contractRepository;
    }

    public function execute(int $contractId, int $organizationId): array
    {
        $contract = $this->contractRepository->findAccessible($contractId, $organizationId);

        if (!$contract) {
            return ['error' => 'Contract not found'];
        }

        return [
            'id' => $contract->id,
            'number' => $contract->number,
            'date' => $contract->date?->format('Y-m-d'),
            'status' => $contract->status->value ?? $contract->status,
            'total_amount' => $contract->total_amount,
            'contractor' => [
                'id' => $contract->contractor->id ?? null,
                'name' => $contract->contractor->name ?? null,
            ],
            'project' => [
                'id' => $contract->project->id ?? null,
                'name' => $contract->project->name ?? null,
            ],
            'start_date' => $contract->start_date?->format('Y-m-d'),
            'end_date' => $contract->end_date?->format('Y-m-d'),
            'notes' => $contract->notes,
        ];
    }
}

