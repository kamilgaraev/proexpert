<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\Project\ProjectContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\ProjectSchedule;
use App\Models\Contractor;
use App\Enums\Schedule\TaskStatusEnum;
use App\Models\Project;
use App\Models\Organization;

class MyProjectsController extends Controller
{
    protected ProjectContextService $projectContextService;

    public function __construct(ProjectContextService $projectContextService)
    {
        $this->projectContextService = $projectContextService;
    }

    /**
     * Получить список всех проектов организации с обзорной информацией
     * 
     * GET /api/v1/landing/my-projects
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $organization = $user->currentOrganization;
            
            if (!$organization) {
                Log::warning('User has no current organization', [
                    'user_id' => $user->id,
                    'current_organization_id' => $user->current_organization_id,
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Организация не найдена',
                ], 404);
            }
            
            $projects = $this->projectContextService->getAccessibleProjects($organization);
            
            $projectsData = array_map(function ($projectData) use ($organization) {
                $project = $projectData['project'];
                $role = $projectData['role'];
                $isOwner = $projectData['is_owner'];
                
                // Получаем статистику проекта
                $stats = $this->getProjectStats($project, $organization);
                
                // Получаем прогресс из графика
                $schedule = ProjectSchedule::where('project_id', $project->id)->first();
                $progress = $schedule ? $schedule->overall_progress_percent : 0;
                
                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'address' => $project->address,
                    'status' => $project->status,
                    'start_date' => $project->start_date?->format('Y-m-d'),
                    'end_date' => $project->end_date?->format('Y-m-d'),
                    'budget_amount' => $project->budget_amount,
                    'is_archived' => $project->is_archived,
                    'role' => [
                        'value' => $role->value,
                        'label' => $role->label(),
                    ],
                    'is_owner' => $isOwner,
                    'stats' => $stats,
                    'progress_percent' => $progress,
                ];
            }, $projects);
            
            // Группируем по роли
            $groupedProjects = [
                'owned' => array_filter($projectsData, fn($p) => $p['is_owner']),
                'participant' => array_filter($projectsData, fn($p) => !$p['is_owner']),
            ];
            
            return response()->json([
                'success' => true,
                'data' => [
                    'projects' => array_values($projectsData),
                    'grouped' => [
                        'owned' => array_values($groupedProjects['owned']),
                        'participant' => array_values($groupedProjects['participant']),
                    ],
                    'totals' => [
                        'all' => count($projectsData),
                        'owned' => count($groupedProjects['owned']),
                        'participant' => count($groupedProjects['participant']),
                        'active' => count(array_filter($projectsData, fn($p) => !$p['is_archived'])),
                        'archived' => count(array_filter($projectsData, fn($p) => $p['is_archived'])),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get my projects', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve projects',
            ], 500);
        }
    }

    /**
     * Получить детальную информацию о проекте
     * 
     * GET /api/v1/landing/my-projects/{project}
     */
    public function show(Request $request, int $project): JsonResponse
    {
        try {
            $user = $request->user();
            $organization = $user->currentOrganization;
            
            if (!$organization) {
                return response()->json([
                    'success' => false,
                    'message' => 'Организация не найдена',
                ], 404);
            }
            
            $projectModel = Project::find($project);
            
            if (!$projectModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Проект не найден',
                ], 404);
            }
            
            // Проверяем доступ
            if (!$this->projectContextService->canOrganizationAccessProject($projectModel, $organization)) {
                return response()->json([
                    'success' => false,
                    'message' => 'У вас нет доступа к этому проекту',
                ], 403);
            }
            
            $role = $this->projectContextService->getOrganizationRole($projectModel, $organization);
            $stats = $this->getProjectStats($projectModel, $organization);
            
            // Получаем расширенные данные
            $schedule = ProjectSchedule::where('project_id', $projectModel->id)->first();
            
            // Данные о задачах (если есть график)
            $tasksStats = ['open' => 0, 'overdue' => 0, 'completed' => 0, 'total' => 0];
            $nextMilestone = null;
            
            if ($schedule) {
                $tasksQuery = $schedule->tasks()->where('task_type', '!=', 'project'); // Исключаем сам проект если он задача
                
                // Если я не владелец, показываем задачи назначенные на моих пользователей?
                // Или задачи где моя организация исполнитель? (В ScheduleTask нет поля organization_id исполнителя, есть assigned_user_id)
                // Пока покажем общую статистику проекта для владельца, и "мои задачи" для участника?
                // Для простоты покажем общие задачи проекта, так как это "Landing" для руководителя.
                
                $tasksStats['total'] = (clone $tasksQuery)->count();
                $tasksStats['completed'] = (clone $tasksQuery)->where('status', TaskStatusEnum::COMPLETED)->count();
                $tasksStats['open'] = (clone $tasksQuery)->whereIn('status', [TaskStatusEnum::NOT_STARTED, TaskStatusEnum::IN_PROGRESS])->count();
                
                // Просроченные
                $tasksStats['overdue'] = (clone $tasksQuery)
                    ->where('planned_end_date', '<', now())
                    ->where('progress_percent', '<', 100)
                    ->whereNotIn('status', [TaskStatusEnum::COMPLETED, TaskStatusEnum::CANCELLED])
                    ->count();
                    
                // Следующая веха
                $nextMilestone = $schedule->milestones()
                    ->where('date', '>=', now())
                    ->orderBy('date')
                    ->first();
            }
            
            // Участники (топ 5 + общее количество)
            $allParticipants = $this->projectContextService->getAllProjectParticipants($projectModel);
            $participantsCount = count($allParticipants);
            $keyParticipants = array_slice($allParticipants, 0, 5);
            $formattedParticipants = array_map(function($p) {
                return [
                    'id' => $p['organization']->id,
                    'name' => $p['organization']->name,
                    'role' => [
                        'value' => $p['role']->value,
                        'label' => $p['role']->label(),
                    ],
                    'logo' => $p['organization']->logo_path,
                ];
            }, $keyParticipants);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'project' => [
                        'id' => $projectModel->id,
                        'name' => $projectModel->name,
                        'address' => $projectModel->address,
                        'description' => $projectModel->description,
                        'customer' => $projectModel->customer,
                        'designer' => $projectModel->designer,
                        'status' => $projectModel->status,
                        'start_date' => $projectModel->start_date?->format('Y-m-d'),
                        'end_date' => $projectModel->end_date?->format('Y-m-d'),
                        'budget_amount' => $projectModel->budget_amount,
                        'site_area_m2' => $projectModel->site_area_m2,
                        'is_archived' => $projectModel->is_archived,
                        'contract_number' => $projectModel->contract_number,
                        'contract_date' => $projectModel->contract_date,
                    ],
                    'role' => [
                        'value' => $role->value,
                        'label' => $role->label(),
                    ],
                    'is_owner' => $projectModel->organization_id === $organization->id,
                    'stats' => $stats,
                    'progress' => [
                        'percent' => $schedule ? $schedule->overall_progress_percent : 0,
                        'health' => $schedule ? $schedule->health_status : 'unknown',
                        'next_milestone' => $nextMilestone ? [
                            'name' => $nextMilestone->name,
                            'date' => $nextMilestone->date->format('Y-m-d'),
                        ] : null,
                    ],
                    'tasks_summary' => $tasksStats,
                    'participants' => [
                        'total' => $participantsCount,
                        'list' => $formattedParticipants,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get project details', [
                'project_id' => $project,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve project details',
            ], 500);
        }
    }

    /**
     * Получить статистику проекта
     */
    private function getProjectStats(Project $project, Organization $currentOrganization): array
    {
        try {
            $projectId = $project->id;
            $organizationId = $currentOrganization->id;
            $projectOwnerId = $project->organization_id;
            
            // Определяем, какие ID подрядчиков соответствуют текущей организации
            // в контексте владельца проекта
            $myContractorIds = [];
            
            if ($projectOwnerId === $organizationId) {
                // Я владелец проекта. 
                // "Мои контракты" - это контракты, где я заказчик? Это все контракты проекта.
                // "Мои работы" - это работы, выполненные МНОЙ (если я сам выполняю работы).
                // Обычно владелец не является подрядчиком у самого себя в системе, но может быть.
                // Для статистики владельца показываем Total. My может быть 0 или "где я исполнитель".
                // Пока оставим My = 0, если не найдем себя в подрядчиках.
                
                // Попробуем найти подрядчика с моим ИНН (если я сам себя добавил как подрядчика)
                 if ($currentOrganization->tax_number) {
                    $myContractorIds = Contractor::where('organization_id', $projectOwnerId)
                        ->where('inn', $currentOrganization->tax_number)
                        ->pluck('id')
                        ->toArray();
                }
            } else {
                // Я участник. Ищем себя в справочнике подрядчиков владельца проекта.
                if ($currentOrganization->tax_number) {
                    $myContractorIds = Contractor::where('organization_id', $projectOwnerId)
                        ->where('inn', $currentOrganization->tax_number)
                        ->pluck('id')
                        ->toArray();
                }
            }
            
            // Статистика контрактов
            $contractsQuery = DB::table('contracts')->where('project_id', $projectId);
            
            $totalContracts = (clone $contractsQuery)->count();
            $totalAmount = (clone $contractsQuery)->sum('total_amount');
            
            $myContracts = 0;
            $myContractsAmount = 0;
            
            if (!empty($myContractorIds)) {
                $myContractsQuery = (clone $contractsQuery)->whereIn('contractor_id', $myContractorIds);
                $myContracts = $myContractsQuery->count();
                $myContractsAmount = $myContractsQuery->sum('total_amount');
            }
            
            // Статистика работ
            $worksQuery = DB::table('completed_works')->where('project_id', $projectId);
            
            $totalWorks = (clone $worksQuery)->count();
            $totalWorksAmount = (clone $worksQuery)->sum('total_amount');
            
            $myWorks = 0;
            $myWorksAmount = 0;
            
            if (!empty($myContractorIds)) {
                // В completed_works может быть contractor_id
                $myWorksQuery = (clone $worksQuery)->whereIn('contractor_id', $myContractorIds);
                $myWorks = $myWorksQuery->count();
                $myWorksAmount = $myWorksQuery->sum('total_amount');
            }
            
            return [
                'contracts' => [
                    'total' => $totalContracts,
                    'my' => $myContracts,
                    'total_amount' => (float)$totalAmount,
                    'my_amount' => (float)$myContractsAmount,
                ],
                'works' => [
                    'total' => $totalWorks,
                    'my' => $myWorks,
                    'total_amount' => (float)$totalWorksAmount,
                    'my_amount' => (float)$myWorksAmount,
                ],
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to get project stats', [
                'project_id' => $project->id,
                'organization_id' => $currentOrganization->id,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'contracts' => ['total' => 0, 'my' => 0, 'total_amount' => 0, 'my_amount' => 0],
                'works' => ['total' => 0, 'my' => 0, 'total_amount' => 0, 'my_amount' => 0],
            ];
        }
    }
}
