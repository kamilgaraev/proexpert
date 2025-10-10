<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Contracts;

use Illuminate\Support\Facades\DB;

class SearchContractsAction
{
    public function execute(int $organizationId, ?array $params = []): array
    {
        $query = DB::table('contracts')
            ->join('contractors', 'contracts.contractor_id', '=', 'contractors.id')
            ->leftJoin('projects', 'contracts.project_id', '=', 'projects.id')
            ->where('contracts.organization_id', $organizationId)
            ->whereNull('contracts.deleted_at');

        if (isset($params['status'])) {
            $query->where('contracts.status', $params['status']);
        }

        if (isset($params['contractor_id'])) {
            $query->where('contracts.contractor_id', $params['contractor_id']);
        }

        if (isset($params['project_id'])) {
            $query->where('contracts.project_id', $params['project_id']);
        }

        if (isset($params['type'])) {
            $query->where('contracts.type', $params['type']);
        }

        if (isset($params['contractor_name'])) {
            $query->where('contractors.name', 'ILIKE', '%' . $params['contractor_name'] . '%');
        }

        if (isset($params['number'])) {
            $query->where('contracts.number', 'ILIKE', '%' . $params['number'] . '%');
        }

        // Проверяем есть ли колонка type в таблице
        $hasTypeColumn = DB::getSchemaBuilder()->hasColumn('contracts', 'type');
        
        $selectColumns = [
            'contracts.id',
            'contracts.number',
            'contracts.date',
            'contracts.subject',
            'contracts.status',
            'contracts.total_amount',
            'contracts.start_date',
            'contracts.end_date',
            'contracts.gp_percentage',
            'contracts.planned_advance_amount',
            'contractors.id as contractor_id',
            'contractors.name as contractor_name',
            'contractors.inn as contractor_inn',
            'projects.id as project_id',
            'projects.name as project_name'
        ];
        
        if ($hasTypeColumn) {
            $selectColumns[] = 'contracts.type';
        }
        
        $contracts = $query
            ->select($selectColumns)
            ->orderByDesc('contracts.date')
            ->limit($params['limit'] ?? 20)
            ->get();

        $statusCounts = $contracts->groupBy('status')
            ->map(fn($group) => count($group))
            ->toArray();

        $typeCounts = [];
        if ($hasTypeColumn) {
            $typeCounts = $contracts->groupBy('type')
                ->map(fn($group) => count($group))
                ->toArray();
        }

        return [
            'total' => count($contracts),
            'contracts' => $contracts->map(function ($contract) use ($hasTypeColumn) {
                $data = [
                    'id' => $contract->id,
                    'number' => $contract->number,
                    'date' => $contract->date,
                    'subject' => $contract->subject,
                    'status' => $contract->status,
                    'total_amount' => (float)$contract->total_amount,
                    'gp_percentage' => (float)($contract->gp_percentage ?? 0),
                    'planned_advance' => (float)($contract->planned_advance_amount ?? 0),
                    'start_date' => $contract->start_date,
                    'end_date' => $contract->end_date,
                    'contractor' => [
                        'id' => $contract->contractor_id,
                        'name' => $contract->contractor_name,
                        'inn' => $contract->contractor_inn,
                    ],
                    'project' => $contract->project_id ? [
                        'id' => $contract->project_id,
                        'name' => $contract->project_name,
                    ] : null,
                ];
                
                if ($hasTypeColumn) {
                    $data['type'] = $contract->type ?? 'contract';
                }
                
                return $data;
            })->toArray(),
            'by_status' => $statusCounts,
            'by_type' => $typeCounts,
            'total_amount' => round($contracts->sum('total_amount'), 2),
        ];
    }
}


