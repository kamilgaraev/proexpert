<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class ContractsPaymentsWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::CONTRACTS_PAYMENTS;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $contracts = DB::table('contracts')
            ->where('organization_id', $request->organizationId)
            ->whereIn('status', ['active', 'in_progress'])
            ->select('id', 'number', 'total_amount', 'actual_advance_amount')
            ->get();

        $data = [];
        $totalExpected = 0;
        $totalReceived = 0;

        foreach ($contracts as $contract) {
            // Используем таблицу payment_documents для расчета оплаченной суммы
            $paid = DB::table('payment_documents')
                ->where('invoiceable_type', 'App\\Models\\Contract')
                ->where('invoiceable_id', $contract->id)
                ->whereNull('deleted_at')
                ->sum('paid_amount');
            
            $expected = (float)$contract->total_amount;
            $received = (float)$paid;
            
            $totalExpected += $expected;
            $totalReceived += $received;

            $data[] = [
                'contract_id' => $contract->id,
                'contract_number' => $contract->number,
                'expected' => $expected,
                'received' => $received,
                'outstanding' => $expected - $received,
            ];
        }

        return [
            'total_expected' => $totalExpected,
            'total_received' => $totalReceived,
            'total_outstanding' => $totalExpected - $totalReceived,
            'by_contract' => $data,
        ];
    }
}

