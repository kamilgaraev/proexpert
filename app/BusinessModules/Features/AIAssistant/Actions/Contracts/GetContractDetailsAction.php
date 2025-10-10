<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Contracts;

use Illuminate\Support\Facades\DB;

class GetContractDetailsAction
{
    public function execute(int $organizationId, ?array $params = []): array
    {
        $contractId = $params['contract_id'] ?? null;
        $contractNumber = $params['contract_number'] ?? null;

        if (!$contractId && !$contractNumber) {
            $contracts = DB::table('contracts')
                ->join('contractors', 'contracts.contractor_id', '=', 'contractors.id')
                ->leftJoin('projects', 'contracts.project_id', '=', 'projects.id')
                ->where('contracts.organization_id', $organizationId)
                ->whereNull('contracts.deleted_at')
                ->select(
                    'contracts.id',
                    'contracts.number',
                    'contracts.status',
                    'contracts.date',
                    'contracts.total_amount',
                    'contractors.name as contractor_name',
                    'projects.name as project_name'
                )
                ->orderByDesc('contracts.date')
                ->limit(10)
                ->get();

            return [
                'show_list' => true,
                'message' => 'Выберите контракт из списка или укажите его номер/ID',
                'contracts' => $contracts->map(function($c) {
                    return [
                        'id' => $c->id,
                        'number' => $c->number,
                        'status' => $c->status,
                        'date' => $c->date,
                        'amount' => (float)$c->total_amount,
                        'contractor' => $c->contractor_name,
                        'project' => $c->project_name,
                    ];
                })->toArray(),
            ];
        }

        $query = DB::table('contracts')
            ->join('contractors', 'contracts.contractor_id', '=', 'contractors.id')
            ->leftJoin('projects', 'contracts.project_id', '=', 'projects.id')
            ->where('contracts.organization_id', $organizationId)
            ->whereNull('contracts.deleted_at');

        if ($contractId) {
            $query->where('contracts.id', $contractId);
        } elseif ($contractNumber) {
            $query->where('contracts.number', 'ILIKE', '%' . $contractNumber . '%');
        }

        // Проверяем есть ли колонка type в таблице
        $hasTypeColumn = DB::getSchemaBuilder()->hasColumn('contracts', 'type');
        
        $contract = $query
            ->select(
                'contracts.*',
                'contractors.id as contractor_id',
                'contractors.name as contractor_name',
                'contractors.inn as contractor_inn',
                'contractors.phone as contractor_phone',
                'contractors.email as contractor_email',
                'contractors.address as contractor_address',
                'projects.id as project_id',
                'projects.name as project_name',
                'projects.address as project_address',
                'projects.status as project_status'
            )
            ->first();

        if (!$contract) {
            return ['error' => 'Контракт не найден'];
        }

        $acts = DB::table('acts')
            ->where('contract_id', $contract->id)
            ->whereNull('deleted_at')
            ->select(
                'id',
                'number',
                'date',
                'total_amount',
                'status'
            )
            ->orderByDesc('date')
            ->get();

        $invoices = DB::table('invoices')
            ->where('contract_id', $contract->id)
            ->whereNull('deleted_at')
            ->select(
                'id',
                'number',
                'date',
                'total_amount',
                'status',
                'payment_date'
            )
            ->orderByDesc('date')
            ->get();

        $totalPaid = $invoices->where('status', 'paid')->sum('total_amount');
        $totalInvoiced = $invoices->sum('total_amount');
        $totalActed = $acts->sum('total_amount');

        return [
            'show_list' => false,
            'contract' => array_merge([
                'id' => $contract->id,
                'number' => $contract->number,
                'date' => $contract->date,
                'subject' => $contract->subject,
                'status' => $contract->status,
                'work_type_category' => $contract->work_type_category,
                'payment_terms' => $contract->payment_terms,
                'total_amount' => (float)$contract->total_amount,
                'gp_percentage' => (float)($contract->gp_percentage ?? 0),
                'planned_advance' => (float)($contract->planned_advance_amount ?? 0),
                'start_date' => $contract->start_date,
                'end_date' => $contract->end_date,
                'notes' => $contract->notes,
            ], $hasTypeColumn ? ['type' => $contract->type ?? 'contract'] : []),
            'contractor' => [
                'id' => $contract->contractor_id,
                'name' => $contract->contractor_name,
                'inn' => $contract->contractor_inn,
                'phone' => $contract->contractor_phone,
                'email' => $contract->contractor_email,
                'address' => $contract->contractor_address,
            ],
            'project' => $contract->project_id ? [
                'id' => $contract->project_id,
                'name' => $contract->project_name,
                'address' => $contract->project_address,
                'status' => $contract->project_status,
            ] : null,
            'financial' => [
                'total_amount' => (float)$contract->total_amount,
                'total_acted' => (float)$totalActed,
                'total_invoiced' => (float)$totalInvoiced,
                'total_paid' => (float)$totalPaid,
                'remaining' => (float)($contract->total_amount - $totalPaid),
                'completion_percentage' => $contract->total_amount > 0 
                    ? round(($totalActed / $contract->total_amount) * 100, 2) 
                    : 0,
            ],
            'acts' => [
                'count' => count($acts),
                'list' => $acts->map(function($act) {
                    return [
                        'id' => $act->id,
                        'number' => $act->number,
                        'date' => $act->date,
                        'amount' => (float)$act->total_amount,
                        'status' => $act->status,
                    ];
                })->toArray(),
            ],
            'invoices' => [
                'count' => count($invoices),
                'list' => $invoices->map(function($invoice) {
                    return [
                        'id' => $invoice->id,
                        'number' => $invoice->number,
                        'date' => $invoice->date,
                        'amount' => (float)$invoice->total_amount,
                        'status' => $invoice->status,
                        'payment_date' => $invoice->payment_date,
                    ];
                })->toArray(),
            ],
        ];
    }
}


