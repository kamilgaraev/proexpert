<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Contracts;

use App\Repositories\ContractRepository;
use App\Models\Contract;

class SearchContractsAction
{
    protected ContractRepository $contractRepository;

    public function __construct(ContractRepository $contractRepository)
    {
        $this->contractRepository = $contractRepository;
    }

    public function execute(int $organizationId, ?array $params = []): array
    {
        $query = Contract::where('organization_id', $organizationId);

        if (isset($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (isset($params['contractor_id'])) {
            $query->where('contractor_id', $params['contractor_id']);
        }

        if (isset($params['project_id'])) {
            $query->where('project_id', $params['project_id']);
        }

        $contracts = $query->with(['contractor', 'project'])
            ->limit($params['limit'] ?? 10)
            ->get();

        return [
            'total' => $contracts->count(),
            'contracts' => $contracts->map(function ($contract) {
                return [
                    'id' => $contract->id,
                    'number' => $contract->number,
                    'status' => $contract->status->value ?? $contract->status,
                    'total_amount' => $contract->total_amount,
                    'contractor' => $contract->contractor->name ?? null,
                    'project' => $contract->project->name ?? null,
                ];
            })->toArray(),
        ];
    }
}

