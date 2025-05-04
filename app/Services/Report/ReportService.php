<?php

namespace App\Services\Report;

use App\Repositories\Interfaces\Log\MaterialUsageLogRepositoryInterface;
use App\Repositories\Interfaces\Log\WorkCompletionLogRepositoryInterface;
use App\Repositories\Interfaces\ProjectRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Exceptions\BusinessLogicException;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ReportService
{
    protected MaterialUsageLogRepositoryInterface $materialLogRepo;
    protected WorkCompletionLogRepositoryInterface $workLogRepo;
    protected ProjectRepositoryInterface $projectRepo;
    protected UserRepositoryInterface $userRepo;

    public function __construct(
        MaterialUsageLogRepositoryInterface $materialLogRepo,
        WorkCompletionLogRepositoryInterface $workLogRepo,
        ProjectRepositoryInterface $projectRepo,
        UserRepositoryInterface $userRepo
    ) {
        $this->materialLogRepo = $materialLogRepo;
        $this->workLogRepo = $workLogRepo;
        $this->projectRepo = $projectRepo;
        $this->userRepo = $userRepo;
    }

    /**
     * Helper для получения ID текущей организации администратора.
     */
    protected function getCurrentOrgId(Request $request): int
    {
        /** @var User|null $user */
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id');
        if (!$organizationId) {
            Log::error('Failed to determine organization context in ReportService', ['user_id' => $user?->id, 'request_attributes' => $request->attributes->all()]);
            throw new BusinessLogicException('Контекст организации не определен для отчетов.', 500);
        }
        return (int)$organizationId;
    }

    /**
     * Подготовка фильтров для отчетов (даты, проект, пользователь и т.д.)
     */
    protected function prepareReportFilters(Request $request, array $allowedFilters): array
    {
        $filters = [];
        foreach ($allowedFilters as $key) {
            if ($request->has($key) && !is_null($request->query($key)) && $request->query($key) !== '') {
                $filters[$key] = $request->query($key);
            }
        }

        if (!empty($filters['date_from'])) {
            try {
                $filters['date_from'] = Carbon::parse($filters['date_from'])->startOfDay();
            } catch (\Exception $e) {
                unset($filters['date_from']);
            }
        }
        if (!empty($filters['date_to'])) {
            try {
                $filters['date_to'] = Carbon::parse($filters['date_to'])->endOfDay();
            } catch (\Exception $e) {
                unset($filters['date_to']);
            }
        }

        return $filters;
    }

    /**
     * Отчет по расходу материалов.
     */
    public function getMaterialUsageReport(Request $request): array
    {
        $organizationId = $this->getCurrentOrgId($request);
        $filters = $this->prepareReportFilters($request, ['project_id', 'material_id', 'user_id', 'date_from', 'date_to']);

        Log::info('Generating Material Usage Report', ['org_id' => $organizationId, 'filters' => $filters]);

        $reportData = $this->materialLogRepo->getAggregatedUsage($organizationId, $filters);

        return [
            'title' => 'Отчет по расходу материалов',
            'filters' => $filters,
            'data' => $reportData,
            'generated_at' => Carbon::now(),
        ];
    }

    /**
     * Отчет по выполненным работам.
     */
    public function getWorkCompletionReport(Request $request): array
    {
        $organizationId = $this->getCurrentOrgId($request);
        $filters = $this->prepareReportFilters($request, ['project_id', 'work_type_id', 'user_id', 'date_from', 'date_to']);

        Log::info('Generating Work Completion Report', ['org_id' => $organizationId, 'filters' => $filters]);

        $reportData = $this->workLogRepo->getAggregatedUsage($organizationId, $filters);

        return [
            'title' => 'Отчет по выполненным работам',
            'filters' => $filters,
            'data' => $reportData,
            'generated_at' => Carbon::now(),
        ];
    }

    /**
     * Отчет по активности прорабов.
     */
    public function getForemanActivityReport(Request $request): array
    {
        $organizationId = $this->getCurrentOrgId($request);
        $filters = $this->prepareReportFilters($request, ['project_id', 'user_id', 'date_from', 'date_to']);

        Log::info('Generating Foreman Activity Report', ['org_id' => $organizationId, 'filters' => $filters]);

        $activityData = $this->userRepo->getForemanActivity($organizationId, $filters);

        return [
            'title' => 'Отчет по активности прорабов',
            'filters' => $filters,
            'data' => $activityData,
            'generated_at' => Carbon::now(),
        ];
    }

    /**
     * Сводный отчет по статусам проектов.
     */
    public function getProjectStatusSummaryReport(Request $request): array
    {
        $organizationId = $this->getCurrentOrgId($request);
        $filters = $this->prepareReportFilters($request, ['status', 'is_archived']);

        Log::info('Generating Project Status Summary Report', ['org_id' => $organizationId, 'filters' => $filters]);

        $projectCounts = $this->projectRepo->getProjectCountsByStatus($organizationId, $filters);

        return [
            'title' => 'Сводный отчет по статусам проектов',
            'filters' => $filters,
            'data' => $projectCounts,
            'generated_at' => Carbon::now(),
        ];
    }
} 