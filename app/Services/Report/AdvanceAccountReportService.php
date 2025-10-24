<?php

namespace App\Services\Report;

use App\Models\AdvanceAccountTransaction;
use App\Models\User;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;
use Symfony\Component\HttpFoundation\Response;
use App\Services\Export\ExcelExporterService;

class AdvanceAccountReportService
{
    protected ExcelExporterService $excelExporter;

    public function __construct(ExcelExporterService $excelExporter)
    {
        $this->excelExporter = $excelExporter;
    }

    /**
     * Получить сводный отчет по подотчетным средствам.
     *
     * @param array $filters
     * @return array
     */
    public function getSummaryReport(array $filters): array
    {
        try {
            $organizationId = $filters['organization_id'] ?? null;
            if (!$organizationId) {
                throw new Exception('Organization ID is required for report generation');
            }

            $dateFrom = isset($filters['date_from']) ? Carbon::parse($filters['date_from']) : Carbon::now()->subDays(30);
            $dateTo = isset($filters['date_to']) ? Carbon::parse($filters['date_to']) : Carbon::now();

            // Получаем сводные данные по транзакциям
            $transactionSummary = AdvanceAccountTransaction::byOrganization($organizationId)
                ->whereBetween('created_at', [$dateFrom->toDateTimeString(), $dateTo->toDateTimeString()])
                ->select([
                    'type',
                    DB::raw('COUNT(*) as count'),
                    DB::raw('SUM(amount) as total_amount'),
                    'reporting_status',
                ])
                ->groupBy(['type', 'reporting_status'])
                ->get()
                ->groupBy('type')
                ->map(function ($group) {
                    return $group->groupBy('reporting_status')->map(function ($items) {
                        return [
                            'count' => $items->sum('count'),
                            'total_amount' => $items->sum('total_amount'),
                        ];
                    });
                });

            // Получаем сводные данные по пользователям
            $userSummary = User::whereHas('organizations', function ($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })
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
                ->get();

            return [
                'title' => 'Сводный отчет по подотчетным средствам',
                'period' => [
                    'from' => $dateFrom->format('Y-m-d'),
                    'to' => $dateTo->format('Y-m-d'),
                ],
                'transaction_summary' => $transactionSummary,
                'top_users' => $userSummary,
                'generated_at' => Carbon::now()->format('Y-m-d H:i:s'),
            ];
        } catch (Exception $e) {
            Log::error('Error generating advance account summary report: ' . $e->getMessage(), [
                'exception' => $e,
                'filters' => $filters
            ]);

            return [
                'error' => 'Failed to generate report',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Получить отчет по подотчетным средствам конкретного пользователя.
     *
     * @param array $filters
     * @return array
     */
    public function getUserReport(array $filters): array
    {
        try {
            $userId = $filters['user_id'] ?? null;
            $organizationId = $filters['organization_id'] ?? null;

            if (!$userId || !$organizationId) {
                throw new Exception('User ID and Organization ID are required for report generation');
            }

            $dateFrom = isset($filters['date_from']) ? Carbon::parse($filters['date_from']) : Carbon::now()->subDays(30);
            $dateTo = isset($filters['date_to']) ? Carbon::parse($filters['date_to']) : Carbon::now();

            // Получаем данные о пользователе
            $user = User::findOrFail($userId);

            // Получаем все транзакции пользователя
            $transactions = AdvanceAccountTransaction::byUser($userId)
                ->byOrganization($organizationId)
                ->whereBetween('created_at', [$dateFrom->toDateTimeString(), $dateTo->toDateTimeString()])
                ->with(['project'])
                ->orderBy('created_at', 'desc')
                ->get();

            // Группируем транзакции по типу
            $summary = [
                'total_issued' => $transactions->where('type', AdvanceAccountTransaction::TYPE_ISSUE)->sum('amount'),
                'total_expense' => $transactions->where('type', AdvanceAccountTransaction::TYPE_EXPENSE)->sum('amount'),
                'total_returned' => $transactions->where('type', AdvanceAccountTransaction::TYPE_RETURN)->sum('amount'),
                'current_balance' => $user->current_balance,
            ];

            // Группируем транзакции по проектам
            $projectSummary = $transactions->groupBy('project_id')->map(function ($group) {
                $project = $group->first()->project;
                return [
                    'project_id' => $project ? $project->id : null,
                    'project_name' => $project ? $project->name : 'Без проекта',
                    'total_amount' => $group->sum('amount'),
                    'transaction_count' => $group->count(),
                ];
            })->values();

            return [
                'title' => 'Отчет по подотчетным средствам пользователя',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'current_balance' => $user->current_balance,
                    'total_issued' => $user->total_issued,
                    'total_reported' => $user->total_reported,
                ],
                'period' => [
                    'from' => $dateFrom->format('Y-m-d'),
                    'to' => $dateTo->format('Y-m-d'),
                ],
                'summary' => $summary,
                'transactions' => $transactions,
                'project_summary' => $projectSummary,
                'generated_at' => Carbon::now()->format('Y-m-d H:i:s'),
            ];
        } catch (Exception $e) {
            Log::error('Error generating user advance account report: ' . $e->getMessage(), [
                'exception' => $e,
                'filters' => $filters
            ]);

            return [
                'error' => 'Failed to generate report',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Получить отчет по подотчетным средствам по проекту.
     *
     * @param array $filters
     * @return array
     */
    public function getProjectReport(array $filters): array
    {
        try {
            $projectId = $filters['project_id'] ?? null;
            $organizationId = $filters['organization_id'] ?? null;

            if (!$projectId || !$organizationId) {
                throw new Exception('Project ID and Organization ID are required for report generation');
            }

            $dateFrom = isset($filters['date_from']) ? Carbon::parse($filters['date_from']) : Carbon::now()->subDays(30);
            $dateTo = isset($filters['date_to']) ? Carbon::parse($filters['date_to']) : Carbon::now();

            // Получаем данные о проекте
            $project = Project::findOrFail($projectId);

            // Получаем все транзакции по проекту
            $transactions = AdvanceAccountTransaction::byProject($projectId)
                ->byOrganization($organizationId)
                ->whereBetween('created_at', [$dateFrom->toDateTimeString(), $dateTo->toDateTimeString()])
                ->with(['user'])
                ->orderBy('created_at', 'desc')
                ->get();

            // Группируем транзакции по типу
            $summary = [
                'total_issued' => $transactions->where('type', AdvanceAccountTransaction::TYPE_ISSUE)->sum('amount'),
                'total_expense' => $transactions->where('type', AdvanceAccountTransaction::TYPE_EXPENSE)->sum('amount'),
                'total_returned' => $transactions->where('type', AdvanceAccountTransaction::TYPE_RETURN)->sum('amount'),
            ];

            // Группируем транзакции по пользователям
            $userSummary = $transactions->groupBy('user_id')->map(function ($group) {
                $user = $group->first()->user;
                return [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'total_amount' => $group->sum('amount'),
                    'transaction_count' => $group->count(),
                ];
            })->values();

            return [
                'title' => 'Отчет по подотчетным средствам проекта',
                'project' => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'external_code' => $project->external_code,
                ],
                'period' => [
                    'from' => $dateFrom->format('Y-m-d'),
                    'to' => $dateTo->format('Y-m-d'),
                ],
                'summary' => $summary,
                'transactions' => $transactions,
                'user_summary' => $userSummary,
                'generated_at' => Carbon::now()->format('Y-m-d H:i:s'),
            ];
        } catch (Exception $e) {
            Log::error('Error generating project advance account report: ' . $e->getMessage(), [
                'exception' => $e,
                'filters' => $filters
            ]);

            return [
                'error' => 'Failed to generate report',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Получить отчет по просроченным подотчетным средствам.
     *
     * @param array $filters
     * @return array
     */
    public function getOverdueReport(array $filters): array
    {
        try {
            $organizationId = $filters['organization_id'] ?? null;
            if (!$organizationId) {
                throw new Exception('Organization ID is required for report generation');
            }

            $overdueDays = $filters['overdue_days'] ?? 30; // По умолчанию 30 дней
            $cutoffDate = Carbon::now()->subDays($overdueDays);

            // Получаем пользователей с просроченными подотчетными средствами
            $users = User::whereHas('organizations', function ($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })
                ->where('has_overdue_balance', true)
                ->where('current_balance', '>', 0)
                ->where(function ($query) use ($cutoffDate) {
                    $query->where('last_transaction_at', '<', $cutoffDate)
                        ->orWhere(function ($q) use ($cutoffDate) {
                            $q->whereNull('last_transaction_at')
                                ->where('updated_at', '<', $cutoffDate);
                        });
                })
                ->select([
                    'id',
                    'name',
                    'current_balance',
                    'last_transaction_at',
                ])
                ->get();

            // Получаем детали по просроченным транзакциям
            $overdueTransactions = AdvanceAccountTransaction::byOrganization($organizationId)
                ->where('reporting_status', AdvanceAccountTransaction::STATUS_PENDING)
                ->where('type', AdvanceAccountTransaction::TYPE_ISSUE)
                ->where('created_at', '<', $cutoffDate)
                ->with(['user', 'project'])
                ->orderBy('created_at')
                ->get();

            return [
                'title' => 'Отчет по просроченным подотчетным средствам',
                'cutoff_date' => $cutoffDate->format('Y-m-d'),
                'overdue_days' => $overdueDays,
                'users_with_overdue_balance' => $users,
                'overdue_transactions' => $overdueTransactions,
                'summary' => [
                    'user_count' => $users->count(),
                    'transaction_count' => $overdueTransactions->count(),
                    'total_overdue_amount' => $users->sum('current_balance'),
                ],
                'generated_at' => Carbon::now()->format('Y-m-d H:i:s'),
            ];
        } catch (Exception $e) {
            Log::error('Error generating overdue advance account report: ' . $e->getMessage(), [
                'exception' => $e,
                'filters' => $filters
            ]);

            return [
                'error' => 'Failed to generate report',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Экспортировать отчет в указанном формате.
     *
     * @param array $filters
     * @param string $format
     * @return Response
     */
    public function exportReport(array $filters, string $format): Response
    {
        try {
            $reportType = $filters['report_type'] ?? 'summary';
            $fileName = 'advance_account_report_' . $reportType . '_' . date('Y-m-d_H-i-s');

            switch ($reportType) {
                case 'user':
                    $reportData = $this->getUserReport($filters);
                    $headers = ['ID', 'Имя', 'Проект', 'Тип транзакции', 'Сумма', 'Дата'];
                    $rows = collect($reportData['transactions'])->map(function($t) {
                        return [
                            $t['id'] ?? ($t->id ?? ''),
                            $t['user']['name'] ?? ($t->user->name ?? ''),
                            $t['project']['name'] ?? ($t->project->name ?? ''),
                            $t['type'] ?? ($t->type ?? ''),
                            $t['amount'] ?? ($t->amount ?? ''),
                            isset($t['created_at']) ? (string)$t['created_at'] : ((string)($t->created_at ?? '')),
                        ];
                    });
                    break;
                case 'project':
                    $reportData = $this->getProjectReport($filters);
                    $headers = ['ID', 'Имя', 'Тип транзакции', 'Сумма', 'Дата', 'Пользователь'];
                    $rows = collect($reportData['transactions'])->map(function($t) {
                        return [
                            $t['id'] ?? ($t->id ?? ''),
                            $t['project']['name'] ?? ($t->project->name ?? ''),
                            $t['type'] ?? ($t->type ?? ''),
                            $t['amount'] ?? ($t->amount ?? ''),
                            isset($t['created_at']) ? (string)$t['created_at'] : ((string)($t->created_at ?? '')),
                            $t['user']['name'] ?? ($t->user->name ?? ''),
                        ];
                    });
                    break;
                case 'overdue':
                    $reportData = $this->getOverdueReport($filters);
                    $headers = ['ID', 'Имя', 'Текущий баланс', 'Последняя транзакция'];
                    $rows = collect($reportData['users_with_overdue_balance'])->map(function($u) {
                        return [
                            $u['id'] ?? ($u->id ?? ''),
                            $u['name'] ?? ($u->name ?? ''),
                            $u['current_balance'] ?? ($u->current_balance ?? ''),
                            isset($u['last_transaction_at']) ? (string)$u['last_transaction_at'] : ((string)($u->last_transaction_at ?? '')),
                        ];
                    });
                    break;
                default:
                    $reportData = $this->getSummaryReport($filters);
                    $headers = ['Тип', 'Статус', 'Количество', 'Сумма'];
                    $rows = collect();
                    foreach ($reportData['transaction_summary'] as $type => $statuses) {
                        foreach ($statuses as $status => $vals) {
                            $rows->push([
                                $type,
                                $status,
                                $vals['count'] ?? '',
                                $vals['total_amount'] ?? '',
                            ]);
                        }
                    }
            }

            if ($format === 'json') {
                $json = json_encode($reportData, JSON_PRETTY_PRINT);
                return response($json, 200, [
                    'Content-Type' => 'application/json',
                    'Content-Disposition' => 'attachment; filename="' . $fileName . '.json"'
                ]);
            }
            if ($format === 'xlsx') {
                return $this->excelExporter->streamDownload($fileName . '.xlsx', $headers, $rows);
            }
            // Для csv пока оставляем заглушку
            return response()->json([
                'message' => 'Экспорт в формате ' . $format . ' временно недоступен. Для экспорта в Excel или CSV используйте JSON формат и конвертируйте его.',
                'data' => $reportData
            ]);
        } catch (Exception $e) {
            Log::error('Error exporting advance account report: ' . $e->getMessage(), [
                'exception' => $e,
                'filters' => $filters,
                'format' => $format
            ]);
            return response()->json([
                'error' => 'Failed to export report',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
} 