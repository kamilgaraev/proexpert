<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\AdvanceAccountTransaction;
use App\Models\Project;
use App\Models\User;
use App\Services\Export\ExcelExporterService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdvanceAccountReportService
{
    public function __construct(
        private readonly ExcelExporterService $excelExporter
    ) {
    }

    public function getSummaryReport(array $filters): array
    {
        [$organizationId, $dateFrom, $dateTo] = $this->resolveBaseFilters($filters);

        $transactionSummary = AdvanceAccountTransaction::query()
            ->byOrganization($organizationId)
            ->whereBetween('document_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->selectRaw('type, reporting_status, COUNT(*) as count, SUM(amount) as total_amount')
            ->groupBy('type', 'reporting_status')
            ->get()
            ->groupBy('type')
            ->map(fn (Collection $group): Collection => $group
                ->keyBy('reporting_status')
                ->map(fn (AdvanceAccountTransaction $item): array => [
                    'count' => (int) $item->getAttribute('count'),
                    'total_amount' => (float) $item->getAttribute('total_amount'),
                ]));

        $topUsers = User::query()
            ->whereHas('organizations', fn ($query) => $query->where('organization_id', $organizationId))
            ->select([
                'id',
                'name',
                'current_balance',
                'total_issued',
                'total_reported',
                'has_overdue_balance',
            ])
            ->orderByDesc('current_balance')
            ->limit(10)
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'current_balance' => (float) $user->current_balance,
                'total_issued' => (float) $user->total_issued,
                'total_reported' => (float) $user->total_reported,
                'has_overdue_balance' => (bool) $user->has_overdue_balance,
            ]);

        return [
            'title' => 'Сводный отчет по подотчетным средствам',
            'period' => $this->formatPeriod($dateFrom, $dateTo),
            'transaction_summary' => $transactionSummary,
            'top_users' => $topUsers,
            'generated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    public function getUserReport(array $filters): array
    {
        [$organizationId, $dateFrom, $dateTo] = $this->resolveBaseFilters($filters);
        $userId = (int) ($filters['user_id'] ?? 0);

        $user = User::query()
            ->whereKey($userId)
            ->whereHas('organizations', fn ($query) => $query->where('organization_id', $organizationId))
            ->firstOrFail();

        $transactions = AdvanceAccountTransaction::query()
            ->byUser($userId)
            ->byOrganization($organizationId)
            ->whereBetween('document_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->with(['project'])
            ->orderByDesc('document_date')
            ->orderByDesc('created_at')
            ->get();

        $summary = [
            'total_issued' => (float) $transactions->where('type', AdvanceAccountTransaction::TYPE_ISSUE)->sum('amount'),
            'total_expense' => (float) $transactions->where('type', AdvanceAccountTransaction::TYPE_EXPENSE)->sum('amount'),
            'total_returned' => (float) $transactions->where('type', AdvanceAccountTransaction::TYPE_RETURN)->sum('amount'),
            'current_balance' => (float) $user->current_balance,
        ];

        $projectSummary = $transactions
            ->groupBy('project_id')
            ->map(function (EloquentCollection $group): array {
                $project = $group->first()->project;

                return [
                    'project_id' => $project?->id,
                    'project_name' => $project?->name ?? 'Без проекта',
                    'total_amount' => (float) $group->sum('amount'),
                    'transaction_count' => $group->count(),
                ];
            })
            ->values();

        return [
            'title' => 'Отчет по подотчетным средствам пользователя',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'current_balance' => (float) $user->current_balance,
                'total_issued' => (float) $user->total_issued,
                'total_reported' => (float) $user->total_reported,
            ],
            'period' => $this->formatPeriod($dateFrom, $dateTo),
            'summary' => $summary,
            'transactions' => $transactions,
            'project_summary' => $projectSummary,
            'generated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    public function getProjectReport(array $filters): array
    {
        [$organizationId, $dateFrom, $dateTo] = $this->resolveBaseFilters($filters);
        $projectId = (int) ($filters['project_id'] ?? 0);

        $project = Project::query()
            ->whereKey($projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $transactions = AdvanceAccountTransaction::query()
            ->byProject($projectId)
            ->byOrganization($organizationId)
            ->whereBetween('document_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->with(['user'])
            ->orderByDesc('document_date')
            ->orderByDesc('created_at')
            ->get();

        $summary = [
            'total_issued' => (float) $transactions->where('type', AdvanceAccountTransaction::TYPE_ISSUE)->sum('amount'),
            'total_expense' => (float) $transactions->where('type', AdvanceAccountTransaction::TYPE_EXPENSE)->sum('amount'),
            'total_returned' => (float) $transactions->where('type', AdvanceAccountTransaction::TYPE_RETURN)->sum('amount'),
        ];

        $userSummary = $transactions
            ->groupBy('user_id')
            ->map(function (EloquentCollection $group): array {
                $user = $group->first()->user;

                return [
                    'user_id' => $user?->id,
                    'user_name' => $user?->name ?? 'Без пользователя',
                    'total_amount' => (float) $group->sum('amount'),
                    'transaction_count' => $group->count(),
                ];
            })
            ->values();

        return [
            'title' => 'Отчет по подотчетным средствам проекта',
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'external_code' => $project->external_code,
            ],
            'period' => $this->formatPeriod($dateFrom, $dateTo),
            'summary' => $summary,
            'transactions' => $transactions,
            'user_summary' => $userSummary,
            'generated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    public function getOverdueReport(array $filters): array
    {
        $organizationId = $this->resolveOrganizationId($filters);
        $overdueDays = max(1, (int) ($filters['overdue_days'] ?? 30));
        $cutoffDate = Carbon::now()->subDays($overdueDays);

        $users = User::query()
            ->whereHas('organizations', fn ($query) => $query->where('organization_id', $organizationId))
            ->where('has_overdue_balance', true)
            ->where('current_balance', '>', 0)
            ->where(function ($query) use ($cutoffDate): void {
                $query->where('last_transaction_at', '<', $cutoffDate)
                    ->orWhere(function ($subQuery) use ($cutoffDate): void {
                        $subQuery->whereNull('last_transaction_at')
                            ->where('updated_at', '<', $cutoffDate);
                    });
            })
            ->select(['id', 'name', 'current_balance', 'last_transaction_at'])
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'current_balance' => (float) $user->current_balance,
                'last_transaction_at' => $user->last_transaction_at?->toDateTimeString(),
            ]);

        $overdueTransactions = AdvanceAccountTransaction::query()
            ->byOrganization($organizationId)
            ->where('reporting_status', AdvanceAccountTransaction::STATUS_PENDING)
            ->where('type', AdvanceAccountTransaction::TYPE_ISSUE)
            ->where('document_date', '<', $cutoffDate->toDateString())
            ->with(['user', 'project'])
            ->orderBy('document_date')
            ->orderBy('created_at')
            ->get();

        return [
            'title' => 'Отчет по просроченным подотчетным средствам',
            'cutoff_date' => $cutoffDate->toDateString(),
            'overdue_days' => $overdueDays,
            'users_with_overdue_balance' => $users,
            'overdue_transactions' => $overdueTransactions,
            'summary' => [
                'user_count' => $users->count(),
                'transaction_count' => $overdueTransactions->count(),
                'total_overdue_amount' => (float) $users->sum('current_balance'),
            ],
            'generated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    public function exportReport(array $filters, string $format): Response|StreamedResponse
    {
        $reportType = (string) ($filters['report_type'] ?? 'summary');
        $reportData = $this->resolveReportData($filters, $reportType);
        $fileName = 'advance_account_report_'.$reportType.'_'.Carbon::now()->format('Y-m-d_H-i-s');
        [$headers, $rows] = $this->resolveExportRows($reportData, $reportType);

        if ($format === 'json') {
            return response(json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 200, [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$fileName.'.json"',
            ]);
        }

        if ($format === 'xlsx') {
            return $this->excelExporter->streamDownload($fileName.'.xlsx', $headers, $rows);
        }

        return response()->streamDownload(function () use ($headers, $rows): void {
            $output = fopen('php://output', 'w');
            fputs($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers);

            foreach ($rows as $row) {
                fputcsv($output, $row);
            }

            fclose($output);
        }, $fileName.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function storeJsonReportSnapshot(array $reportData, string $baseFilename): bool
    {
        try {
            $content = json_encode($reportData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            if ($content === false) {
                return false;
            }

            return $this->excelExporter->storeReportInPersonalFiles(
                $baseFilename.'_'.Carbon::now()->format('d-m-Y_H-i').'.json',
                $content
            );
        } catch (\Throwable $exception) {
            Log::warning('advance_account_report.snapshot_failed', [
                'report' => $baseFilename,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function resolveReportData(array $filters, string $reportType): array
    {
        return match ($reportType) {
            'user' => $this->getUserReport($filters),
            'project' => $this->getProjectReport($filters),
            'overdue' => $this->getOverdueReport($filters),
            default => $this->getSummaryReport($filters),
        };
    }

    private function resolveExportRows(array $reportData, string $reportType): array
    {
        return match ($reportType) {
            'user' => [
                ['ID', 'Пользователь', 'Проект', 'Тип транзакции', 'Сумма', 'Дата документа'],
                collect($reportData['transactions'])->map(fn (AdvanceAccountTransaction $transaction): array => [
                    $transaction->id,
                    $transaction->user?->name,
                    $transaction->project?->name,
                    $transaction->type,
                    (float) $transaction->amount,
                    $transaction->document_date?->toDateString(),
                ]),
            ],
            'project' => [
                ['ID', 'Проект', 'Тип транзакции', 'Сумма', 'Дата документа', 'Пользователь'],
                collect($reportData['transactions'])->map(fn (AdvanceAccountTransaction $transaction): array => [
                    $transaction->id,
                    $transaction->project?->name,
                    $transaction->type,
                    (float) $transaction->amount,
                    $transaction->document_date?->toDateString(),
                    $transaction->user?->name,
                ]),
            ],
            'overdue' => [
                ['ID', 'Пользователь', 'Текущий баланс', 'Последняя транзакция'],
                collect($reportData['users_with_overdue_balance'])->map(fn (array $user): array => [
                    $user['id'],
                    $user['name'],
                    $user['current_balance'],
                    $user['last_transaction_at'],
                ]),
            ],
            default => $this->resolveSummaryExportRows($reportData),
        };
    }

    private function resolveSummaryExportRows(array $reportData): array
    {
        $rows = collect();

        foreach ($reportData['transaction_summary'] as $type => $statuses) {
            foreach ($statuses as $status => $values) {
                $rows->push([
                    $type,
                    $status,
                    $values['count'] ?? 0,
                    $values['total_amount'] ?? 0,
                ]);
            }
        }

        return [
            ['Тип', 'Статус', 'Количество', 'Сумма'],
            $rows,
        ];
    }

    private function resolveBaseFilters(array $filters): array
    {
        $organizationId = $this->resolveOrganizationId($filters);
        $dateFrom = !empty($filters['date_from'])
            ? Carbon::parse($filters['date_from'])->startOfDay()
            : Carbon::now()->subDays(30)->startOfDay();
        $dateTo = !empty($filters['date_to'])
            ? Carbon::parse($filters['date_to'])->endOfDay()
            : Carbon::now()->endOfDay();

        return [$organizationId, $dateFrom, $dateTo];
    }

    private function resolveOrganizationId(array $filters): int
    {
        $organizationId = (int) ($filters['organization_id'] ?? 0);

        if ($organizationId <= 0) {
            throw new \InvalidArgumentException('Organization ID is required for report generation.');
        }

        return $organizationId;
    }

    private function formatPeriod(Carbon $dateFrom, Carbon $dateTo): array
    {
        return [
            'from' => $dateFrom->toDateString(),
            'to' => $dateTo->toDateString(),
        ];
    }
}
