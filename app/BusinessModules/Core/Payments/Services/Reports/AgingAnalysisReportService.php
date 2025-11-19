<?php

namespace App\BusinessModules\Core\Payments\Services\Reports;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Сервис отчета Aging Analysis (Анализ старения дебиторской/кредиторской задолженности)
 */
class AgingAnalysisReportService
{
    private array $agingBuckets = [
        'current' => ['label' => 'Текущие (0-30 дней)', 'days' => [0, 30]],
        '30-60' => ['label' => '30-60 дней', 'days' => [31, 60]],
        '60-90' => ['label' => '60-90 дней', 'days' => [61, 90]],
        '90-120' => ['label' => '90-120 дней', 'days' => [91, 120]],
        '120+' => ['label' => 'Более 120 дней', 'days' => [121, 999999]],
    ];

    /**
     * Получить полный отчет Aging Analysis
     */
    public function generate(int $organizationId, ?Carbon $asOfDate = null): array
    {
        $asOfDate = $asOfDate ?? Carbon::now();

        return [
            'as_of_date' => $asOfDate->format('Y-m-d'),
            'accounts_receivable' => $this->getReceivableAging($organizationId, $asOfDate),
            'accounts_payable' => $this->getPayableAging($organizationId, $asOfDate),
        ];
    }

    /**
     * Анализ дебиторской задолженности (Receivable)
     */
    public function getReceivableAging(int $organizationId, Carbon $asOfDate): array
    {
        // Неоплаченные входящие платежи (нам должны)
        $receivables = PaymentDocument::where('organization_id', $organizationId)
            ->whereIn('status', ['approved', 'scheduled', 'partially_paid'])
            ->whereNotNull('payer_contractor_id')
            ->where('remaining_amount', '>', 0)
            ->with(['payerContractor', 'project'])
            ->get();

        return $this->analyzeAging($receivables, $asOfDate, 'receivable');
    }

    /**
     * Анализ кредиторской задолженности (Payable)
     */
    public function getPayableAging(int $organizationId, Carbon $asOfDate): array
    {
        // Неоплаченные исходящие платежи (мы должны)
        $payables = PaymentDocument::where('organization_id', $organizationId)
            ->whereIn('status', ['approved', 'scheduled', 'partially_paid'])
            ->whereNotNull('payee_contractor_id')
            ->where('remaining_amount', '>', 0)
            ->with(['payeeContractor', 'project'])
            ->get();

        return $this->analyzeAging($payables, $asOfDate, 'payable');
    }

    /**
     * Анализ старения задолженности
     */
    private function analyzeAging(Collection $documents, Carbon $asOfDate, string $type): array
    {
        $agingData = [];
        $totalAmount = 0;

        // Инициализируем buckets
        foreach ($this->agingBuckets as $key => $bucket) {
            $agingData[$key] = [
                'label' => $bucket['label'],
                'amount' => 0,
                'count' => 0,
                'documents' => [],
            ];
        }

        // Распределяем документы по buckets
        foreach ($documents as $document) {
            $dueDate = $document->due_date;
            if (!$dueDate) {
                continue;
            }

            $daysOverdue = $asOfDate->diffInDays($dueDate, false);
            $daysOverdue = $daysOverdue < 0 ? abs($daysOverdue) : 0; // Если еще не наступил срок - 0

            $bucket = $this->determineBucket($daysOverdue);
            
            $amount = $document->remaining_amount;
            $totalAmount += $amount;

            $agingData[$bucket]['amount'] += $amount;
            $agingData[$bucket]['count']++;
            $agingData[$bucket]['documents'][] = [
                'id' => $document->id,
                'document_number' => $document->document_number,
                'contractor_name' => $type === 'receivable' 
                    ? $document->payerContractor?->name ?? 'Неизвестно'
                    : $document->payeeContractor?->name ?? 'Неизвестно',
                'amount' => round($amount, 2),
                'due_date' => $dueDate->format('Y-m-d'),
                'days_overdue' => $daysOverdue,
                'project_name' => $document->project?->name,
            ];
        }

        // Рассчитываем проценты и округляем
        foreach ($agingData as $key => &$bucket) {
            $bucket['amount'] = round($bucket['amount'], 2);
            $bucket['percentage'] = $totalAmount > 0 
                ? round(($bucket['amount'] / $totalAmount) * 100, 2) 
                : 0;
            
            // Сортируем документы по сумме (больше сначала)
            usort($bucket['documents'], fn($a, $b) => $b['amount'] <=> $a['amount']);
        }

        // Топ должников/кредиторов
        $byContractor = $this->groupByContractor($documents, $type);

        return [
            'total_amount' => round($totalAmount, 2),
            'total_count' => $documents->count(),
            'aging_buckets' => $agingData,
            'by_contractor' => $byContractor,
            'overdue_amount' => round($agingData['30-60']['amount'] + $agingData['60-90']['amount'] + $agingData['90-120']['amount'] + $agingData['120+']['amount'], 2),
            'overdue_count' => $agingData['30-60']['count'] + $agingData['60-90']['count'] + $agingData['90-120']['count'] + $agingData['120+']['count'],
        ];
    }

    /**
     * Определить bucket для количества дней просрочки
     */
    private function determineBucket(int $daysOverdue): string
    {
        foreach ($this->agingBuckets as $key => $bucket) {
            if ($daysOverdue >= $bucket['days'][0] && $daysOverdue <= $bucket['days'][1]) {
                return $key;
            }
        }

        return '120+';
    }

    /**
     * Группировка по контрагентам
     */
    private function groupByContractor(Collection $documents, string $type): array
    {
        $grouped = $documents->groupBy(function($doc) use ($type) {
            $contractor = $type === 'receivable' 
                ? $doc->payerContractor 
                : $doc->payeeContractor;
            return $contractor ? $contractor->id : 0;
        });

        $result = $grouped->map(function($items, $contractorId) use ($type) {
            $contractor = $type === 'receivable' 
                ? $items->first()->payerContractor 
                : $items->first()->payeeContractor;

            $totalAmount = $items->sum('remaining_amount');
            
            // Рассчитываем средний срок просрочки
            $totalDaysOverdue = $items->sum(function($doc) {
                return $doc->due_date ? max(0, Carbon::now()->diffInDays($doc->due_date)) : 0;
            });
            $avgDaysOverdue = $items->count() > 0 ? round($totalDaysOverdue / $items->count(), 1) : 0;

            return [
                'contractor_id' => $contractorId,
                'contractor_name' => $contractor?->name ?? 'Неизвестно',
                'total_amount' => round($totalAmount, 2),
                'documents_count' => $items->count(),
                'avg_days_overdue' => $avgDaysOverdue,
                'oldest_due_date' => $items->min('due_date')?->format('Y-m-d'),
            ];
        })->sortByDesc('total_amount')->values();

        return $result->take(20)->toArray();
    }

    /**
     * Получить контрагентов с критической просрочкой
     */
    public function getCriticalContractors(int $organizationId, int $minDaysOverdue = 90): array
    {
        $documents = PaymentDocument::where('organization_id', $organizationId)
            ->whereIn('status', ['approved', 'scheduled', 'partially_paid'])
            ->where('remaining_amount', '>', 0)
            ->where('due_date', '<', Carbon::now()->subDays($minDaysOverdue))
            ->with(['payerContractor', 'payeeContractor'])
            ->get();

        $receivable = $documents->filter(fn($d) => $d->payerContractor !== null)
            ->groupBy('payer_contractor_id');
        
        $payable = $documents->filter(fn($d) => $d->payeeContractor !== null)
            ->groupBy('payee_contractor_id');

        return [
            'receivable_critical' => $this->formatCriticalContractors($receivable, 'payer'),
            'payable_critical' => $this->formatCriticalContractors($payable, 'payee'),
        ];
    }

    /**
     * Форматировать критических контрагентов
     */
    private function formatCriticalContractors(Collection $grouped, string $type): array
    {
        return $grouped->map(function($items, $contractorId) use ($type) {
            $contractor = $type === 'payer' 
                ? $items->first()->payerContractor 
                : $items->first()->payeeContractor;

            return [
                'contractor_id' => $contractorId,
                'contractor_name' => $contractor?->name ?? 'Неизвестно',
                'total_amount' => round($items->sum('remaining_amount'), 2),
                'documents_count' => $items->count(),
                'max_days_overdue' => Carbon::now()->diffInDays($items->min('due_date')),
            ];
        })->sortByDesc('total_amount')->values()->toArray();
    }
}

