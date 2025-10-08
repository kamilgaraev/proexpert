<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReceivablesPayablesWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::RECEIVABLES_PAYABLES;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $receivables = $this->calculateReceivables($request->organizationId);
        $payables = $this->calculatePayables($request->organizationId);

        return [
            'as_of_date' => Carbon::now()->toIso8601String(),
            'receivables' => [
                'total' => $receivables['total'],
                'current' => $receivables['current'],
                'overdue_30' => $receivables['overdue_30'],
                'overdue_60' => $receivables['overdue_60'],
                'overdue_90_plus' => $receivables['overdue_90_plus'],
                'by_contract' => $receivables['by_contract'],
            ],
            'payables' => [
                'total' => $payables['total'],
                'current' => $payables['current'],
                'overdue_30' => $payables['overdue_30'],
                'overdue_60' => $payables['overdue_60'],
                'overdue_90_plus' => $payables['overdue_90_plus'],
                'by_supplier' => $payables['by_supplier'],
            ],
            'net_position' => $receivables['total'] - $payables['total'],
        ];
    }

    protected function calculateReceivables(int $organizationId): array
    {
        try {
            $now = Carbon::now();

            $contracts = Contract::where('organization_id', $organizationId)
                ->whereIn('status', ['active', 'in_progress'])
                ->get();

            $total = 0.0;
            $current = 0.0;
            $overdue30 = 0.0;
            $overdue60 = 0.0;
            $overdue90Plus = 0.0;
            $byContract = [];

            foreach ($contracts as $contract) {
                $completedAmount = DB::table('completed_works')
                    ->where('contract_id', $contract->id)
                    ->sum(DB::raw('quantity * price'));

                $paidAmount = DB::table('contract_payments')
                    ->where('contract_id', $contract->id)
                    ->where('status', 'completed')
                    ->sum('amount');

                $outstanding = (float)($completedAmount - $paidAmount);

                if ($outstanding <= 0) {
                    continue;
                }

                $total += $outstanding;

                $endDate = $contract->end_date ? Carbon::parse($contract->end_date) : $now;
                $daysOverdue = max(0, $now->diffInDays($endDate, false));

                if ($daysOverdue <= 0) {
                    $current += $outstanding;
                } elseif ($daysOverdue <= 30) {
                    $overdue30 += $outstanding;
                } elseif ($daysOverdue <= 60) {
                    $overdue60 += $outstanding;
                } else {
                    $overdue90Plus += $outstanding;
                }

                $byContract[] = [
                    'contract_id' => $contract->id,
                    'contract_number' => $contract->number,
                    'amount' => $outstanding,
                    'days_overdue' => abs($daysOverdue),
                ];
            }

            return [
                'total' => $total,
                'current' => $current,
                'overdue_30' => $overdue30,
                'overdue_60' => $overdue60,
                'overdue_90_plus' => $overdue90Plus,
                'by_contract' => $byContract,
            ];
        } catch (\Exception $e) {
            return [
                'total' => 0.0,
                'current' => 0.0,
                'overdue_30' => 0.0,
                'overdue_60' => 0.0,
                'overdue_90_plus' => 0.0,
                'by_contract' => [],
            ];
        }
    }

    protected function calculatePayables(int $organizationId): array
    {
        try {
            $now = Carbon::now();

            $materialReceipts = DB::table('material_receipts')
                ->join('projects', 'material_receipts.project_id', '=', 'projects.id')
                ->where('projects.organization_id', $organizationId)
                ->whereIn('material_receipts.status', ['confirmed'])
                ->select('material_receipts.*')
                ->get();

            $total = 0.0;
            $current = 0.0;
            $overdue30 = 0.0;
            $overdue60 = 0.0;
            $overdue90Plus = 0.0;
            $bySupplier = [];

            foreach ($materialReceipts as $receipt) {
                $amount = (float)($receipt->total_amount ?? 0);

                if ($amount <= 0) {
                    continue;
                }

                $total += $amount;

                $receiptDate = Carbon::parse($receipt->receipt_date);
                $daysOutstanding = $now->diffInDays($receiptDate);

                if ($daysOutstanding <= 30) {
                    $current += $amount;
                } elseif ($daysOutstanding <= 60) {
                    $overdue30 += $amount;
                } elseif ($daysOutstanding <= 90) {
                    $overdue60 += $amount;
                } else {
                    $overdue90Plus += $amount;
                }

                $supplierName = $receipt->supplier ?? 'Unknown';

                if (!isset($bySupplier[$supplierName])) {
                    $bySupplier[$supplierName] = 0.0;
                }

                $bySupplier[$supplierName] += $amount;
            }

            $bySupplierArray = [];
            foreach ($bySupplier as $supplier => $amount) {
                $bySupplierArray[] = [
                    'supplier' => $supplier,
                    'amount' => $amount,
                ];
            }

            usort($bySupplierArray, fn($a, $b) => $b['amount'] <=> $a['amount']);

            return [
                'total' => $total,
                'current' => $current,
                'overdue_30' => $overdue30,
                'overdue_60' => $overdue60,
                'overdue_90_plus' => $overdue90Plus,
                'by_supplier' => $bySupplierArray,
            ];
        } catch (\Exception $e) {
            return [
                'total' => 0.0,
                'current' => 0.0,
                'overdue_30' => 0.0,
                'overdue_60' => 0.0,
                'overdue_90_plus' => 0.0,
                'by_supplier' => [],
            ];
        }
    }
}

