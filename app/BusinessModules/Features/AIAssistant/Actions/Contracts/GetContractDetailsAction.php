<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Contracts;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantLegacyFinancialRead;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class GetContractDetailsAction
{
    public function execute(int $organizationId, ?array $params = [], ?User $actor = null): array
    {
        if ($actor === null || ! app(AssistantDataAccessPolicy::class)->canReadDomain($actor, $organizationId, 'contracts') || ! app(AssistantDataAccessPolicy::class)->canReadDomain($actor, $organizationId, 'finance')) {
            return [];
        }

        $finance = app(AssistantLegacyFinancialRead::class);
        $canReadMoney = $finance->allowed($actor, $organizationId, 'finance.view');
        $contractId = $params['contract_id'] ?? null;
        $contractNumber = $params['contract_number'] ?? null;

        if (! $contractId && ! $contractNumber) {
            $contracts = DB::table('contracts')
                ->join('contractors', 'contracts.contractor_id', '=', 'contractors.id')
                ->leftJoin('projects', 'contracts.project_id', '=', 'projects.id')
                ->where('contracts.organization_id', $organizationId)
                ->whereNull('contracts.deleted_at')
                ->whereIn('contracts.id', app(AssistantDataAccessPolicy::class)->entityQuery($actor, $organizationId, 'contract')->select('contracts.id'))
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
                'contracts' => $contracts->map(function ($c) use ($canReadMoney) {
                    return [
                        'id' => $c->id,
                        'number' => $c->number,
                        'status' => $c->status,
                        'date' => $c->date,
                        'amount' => $canReadMoney ? AssistantLegacyFinancialRead::money($c->total_amount) : null,
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
            ->whereNull('contracts.deleted_at')
            ->whereIn('contracts.id', app(AssistantDataAccessPolicy::class)->entityQuery($actor, $organizationId, 'contract')->select('contracts.id'));

        if ($contractId) {
            $query->where('contracts.id', $contractId);
        } elseif ($contractNumber) {
            $query->where('contracts.number', 'ILIKE', '%'.$contractNumber.'%');
        }

        // Проверяем есть ли колонка type в таблице
        $hasTypeColumn = DB::getSchemaBuilder()->hasColumn('contracts', 'type');
        $hasActualAdvance = DB::getSchemaBuilder()->hasColumn('contracts', 'actual_advance_amount');

        $contract = $query
            ->select(
                'contracts.*',
                'contractors.id as contractor_id',
                'contractors.name as contractor_name',
                'contractors.inn as contractor_inn',
                'contractors.phone as contractor_phone',
                'contractors.email as contractor_email',
                'contractors.legal_address as contractor_address',
                'projects.id as project_id',
                'projects.name as project_name',
                'projects.address as project_address',
                'projects.status as project_status'
            )
            ->first();

        if (! $contract) {
            return ['error' => 'Контракт не найден'];
        }

        $policy = app(AssistantDataAccessPolicy::class);
        $actScope = $policy->entityQuery($actor, $organizationId, 'performance_act');
        $documentScope = $policy->entityQuery($actor, $organizationId, 'payment_document');
        $currency = $contract->currency;
        $acts = collect();
        $documents = collect();
        $actsAvailable = $canReadMoney && $actScope !== null && is_string($currency) && $currency !== '';
        $documentsAvailable = $canReadMoney && $documentScope !== null && is_string($currency) && $currency !== '';
        if ($actsAvailable) {
            $acts = DB::table('contract_performance_acts')->where('contract_id', $contract->id)
                ->whereIn('id', $actScope->select('contract_performance_acts.id'))
                ->select('id', 'act_document_number as number', 'act_date as date', 'amount as total_amount', 'status', 'currency')
                ->orderByDesc('act_date')->get();
            $actsAvailable = count($acts) === DB::table('contract_performance_acts')->where('contract_id', $contract->id)->count() && ! $acts->contains(static fn ($row): bool => $row->currency !== $currency);
        }
        if ($documentsAvailable) {
            $documents = DB::table('payment_documents')->where('organization_id', $organizationId)
                ->whereIn('invoiceable_type', ['App\\Models\\Contract', (new \App\Models\Contract)->getMorphClass()])
                ->where('invoiceable_id', $contract->id)->whereNull('deleted_at')
                ->whereIn('id', $documentScope->select('payment_documents.id'))
                ->select('id', 'document_number as number', 'document_date as date', 'amount as total_amount', 'status', 'paid_at as payment_date', 'currency')
                ->orderByDesc('document_date')->get();
            $documentsAvailable = count($documents) === DB::table('payment_documents')->where('organization_id', $organizationId)->whereIn('invoiceable_type', ['App\\Models\\Contract', (new \App\Models\Contract)->getMorphClass()])->where('invoiceable_id', $contract->id)->whereNull('deleted_at')->count() && ! $documents->contains(static fn ($row): bool => $row->currency !== $currency);
        }
        $totalPaid = $documentsAvailable ? AssistantLegacyFinancialRead::sum($documents->where('status', 'paid')->pluck('total_amount')) : null;
        $totalInvoiced = $documentsAvailable ? AssistantLegacyFinancialRead::sum($documents->pluck('total_amount')) : null;
        $totalActed = $actsAvailable ? AssistantLegacyFinancialRead::sum($acts->pluck('total_amount')) : null;
        $amount = $canReadMoney ? AssistantLegacyFinancialRead::money($contract->total_amount) : null;
        $plannedAdvance = $canReadMoney ? AssistantLegacyFinancialRead::money($contract->planned_advance_amount) : null;
        $actualAdvance = $canReadMoney ? AssistantLegacyFinancialRead::money($contract->actual_advance_amount ?? null) : null;
        $remainingAdvance = AssistantLegacyFinancialRead::subtract($plannedAdvance, $actualAdvance);

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
                'total_amount' => $amount,
                'gp_percentage' => $canReadMoney ? AssistantLegacyFinancialRead::money($contract->gp_percentage ?? null) : null,
                'gp_amount' => $canReadMoney ? AssistantLegacyFinancialRead::money($contract->gp_amount ?? null) : null,
                'total_amount_with_gp' => $canReadMoney ? AssistantLegacyFinancialRead::money($contract->total_amount_with_gp ?? $contract->total_amount) : null,
                'planned_advance' => $plannedAdvance,
                'actual_advance' => $actualAdvance,
                'remaining_advance' => $remainingAdvance === null ? null : (BigDecimal::of($remainingAdvance)->isLessThan(0) ? '0.00' : $remainingAdvance),
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
                'currency' => $currency,
                'total_amount' => $amount,
                'total_acted' => $totalActed,
                'total_invoiced' => $totalInvoiced,
                'total_paid' => $totalPaid,
                'remaining' => AssistantLegacyFinancialRead::subtract($amount, $totalPaid),
                'completion_percentage' => AssistantLegacyFinancialRead::percentage($totalActed, $amount),
            ],
            'acts' => [
                'count' => $actsAvailable ? count($acts) : null,
                'list' => $acts->map(function ($act) {
                    return [
                        'id' => $act->id,
                        'number' => $act->number,
                        'date' => $act->date,
                        'amount' => AssistantLegacyFinancialRead::money($act->total_amount),
                        'currency' => $act->currency,
                        'status' => $act->status,
                    ];
                })->toArray(),
            ],
            'invoices' => [
                'count' => $documentsAvailable ? count($documents) : null,
                'list' => $documents->map(function ($document) {
                    return [
                        'id' => $document->id,
                        'number' => $document->number,
                        'date' => $document->date,
                        'amount' => AssistantLegacyFinancialRead::money($document->total_amount),
                        'currency' => $document->currency,
                        'status' => $document->status,
                        'payment_date' => $document->payment_date,
                    ];
                })->toArray(),
            ],
        ];
    }
}
