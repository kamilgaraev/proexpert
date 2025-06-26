<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Landing\MultiOrganizationService;
use App\Models\OrganizationGroup;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class HoldingApiController extends Controller
{
    protected MultiOrganizationService $multiOrganizationService;

    public function __construct(MultiOrganizationService $multiOrganizationService)
    {
        $this->multiOrganizationService = $multiOrganizationService;
    }

    public function getPublicData(string $slug): JsonResponse
    {
        try {
            $group = OrganizationGroup::where('slug', $slug)->first();
            
            if (!$group) {
                return response()->json([
                    'success' => false,
                    'message' => 'Холдинг не найден'
                ], 404);
            }

            $parentOrg = $group->parentOrganization;
            if (!$parentOrg) {
                return response()->json([
                    'success' => false,
                    'message' => 'Родительская организация не найдена'
                ], 404);
            }

            $childOrgs = $parentOrg->childOrganizations;
            
            $stats = [
                'total_child_organizations' => $childOrgs->count(),
                'total_users' => $parentOrg->users()->count() + $childOrgs->sum(fn($org) => $org->users()->count()),
                'total_projects' => $parentOrg->projects()->count() + $childOrgs->sum(fn($org) => $org->projects()->count()),
                'total_contracts' => $parentOrg->contracts()->count() + $childOrgs->sum(fn($org) => $org->contracts()->count()),
                'total_contracts_value' => $parentOrg->contracts()->sum('total_amount') + $childOrgs->sum(fn($org) => $org->contracts()->sum('total_amount')),
                'active_contracts_count' => $parentOrg->contracts()->where('status', 'active')->count() + $childOrgs->sum(fn($org) => $org->contracts()->where('status', 'active')->count()),
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'holding' => [
                        'id' => $group->id,
                        'name' => $group->name,
                        'slug' => $group->slug,
                        'description' => $group->description,
                        'parent_organization_id' => $group->parent_organization_id,
                        'status' => $group->status,
                        'created_at' => $group->created_at,
                    ],
                    'parent_organization' => [
                        'id' => $parentOrg->id,
                        'name' => $parentOrg->name,
                        'legal_name' => $parentOrg->legal_name,
                        'tax_number' => $parentOrg->tax_number,
                        'registration_number' => $parentOrg->registration_number,
                        'address' => $parentOrg->address,
                        'phone' => $parentOrg->phone,
                        'email' => $parentOrg->email,
                        'city' => $parentOrg->city,
                        'description' => $parentOrg->description,
                    ],
                    'stats' => $stats,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении данных холдинга',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getDashboardData(string $slug, Request $request): JsonResponse
    {
        try {
            $group = OrganizationGroup::where('slug', $slug)->first();
            
            if (!$group) {
                return response()->json([
                    'success' => false,
                    'message' => 'Холдинг не найден'
                ], 404);
            }

            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Необходима авторизация'
                ], 401);
            }

            $parentOrg = $group->parentOrganization;
            if (!$parentOrg) {
                return response()->json([
                    'success' => false,
                    'message' => 'Родительская организация не найдена'
                ], 404);
            }

            $hasAccess = $this->multiOrganizationService->hasAccessToOrganization($user, $parentOrg->id);
            if (!$hasAccess) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет доступа к управлению холдингом'
                ], 403);
            }

            $hierarchy = $this->multiOrganizationService->getOrganizationHierarchy($parentOrg->id);
            
            $childOrgs = $parentOrg->childOrganizations;
            $recentActivity = [];

            foreach ($childOrgs as $childOrg) {
                $lastProject = $childOrg->projects()->latest()->first();
                $lastContract = $childOrg->contracts()->latest()->first();
                
                if ($lastProject) {
                    $recentActivity[] = [
                        'type' => 'project_created',
                        'organization_name' => $childOrg->name,
                        'description' => "Создан проект: {$lastProject->name}",
                        'date' => $lastProject->created_at,
                    ];
                }
                
                if ($lastContract) {
                    $recentActivity[] = [
                        'type' => 'contract_signed',
                        'organization_name' => $childOrg->name,
                        'description' => "Подписан контракт: {$lastContract->name}",
                        'date' => $lastContract->created_at,
                    ];
                }
            }

            usort($recentActivity, fn($a, $b) => $b['date'] <=> $a['date']);
            $recentActivity = array_slice($recentActivity, 0, 10);

            $consolidatedStats = [
                'total_child_organizations' => $childOrgs->count(),
                'total_users' => $parentOrg->users()->count() + $childOrgs->sum(fn($org) => $org->users()->count()),
                'total_projects' => $parentOrg->projects()->count() + $childOrgs->sum(fn($org) => $org->projects()->count()),
                'total_contracts' => $parentOrg->contracts()->count() + $childOrgs->sum(fn($org) => $org->contracts()->count()),
                'total_contracts_value' => $parentOrg->contracts()->sum('total_amount') + $childOrgs->sum(fn($org) => $org->contracts()->sum('total_amount')),
                'active_contracts_count' => $parentOrg->contracts()->where('status', 'active')->count() + $childOrgs->sum(fn($org) => $org->contracts()->where('status', 'active')->count()),
                'recent_activity' => $recentActivity,
                'performance_metrics' => [
                    'monthly_growth' => 0,
                    'efficiency_score' => 0,
                    'satisfaction_index' => 0,
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'holding' => [
                        'id' => $group->id,
                        'name' => $group->name,
                        'slug' => $group->slug,
                        'description' => $group->description,
                        'parent_organization_id' => $group->parent_organization_id,
                        'status' => $group->status,
                    ],
                    'hierarchy' => $hierarchy,
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                    'consolidated_stats' => $consolidatedStats,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении данных панели управления',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getOrganizations(string $slug, Request $request): JsonResponse
    {
        try {
            $group = OrganizationGroup::where('slug', $slug)->first();
            
            if (!$group) {
                return response()->json([
                    'success' => false,
                    'message' => 'Холдинг не найден'
                ], 404);
            }

            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Необходима авторизация'
                ], 401);
            }

            $parentOrg = $group->parentOrganization;
            if (!$parentOrg) {
                return response()->json([
                    'success' => false,
                    'message' => 'Родительская организация не найдена'
                ], 404);
            }

            $hasAccess = $this->multiOrganizationService->hasAccessToOrganization($user, $parentOrg->id);
            if (!$hasAccess) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет доступа к данным организаций холдинга'
                ], 403);
            }

            $childOrgs = $parentOrg->childOrganizations;
            
            $organizations = $childOrgs->map(function ($org) {
                return [
                    'id' => $org->id,
                    'name' => $org->name,
                    'description' => $org->description,
                    'organization_type' => $org->organization_type,
                    'hierarchy_level' => $org->hierarchy_level,
                    'tax_number' => $org->tax_number,
                    'registration_number' => $org->registration_number,
                    'address' => $org->address,
                    'phone' => $org->phone,
                    'email' => $org->email,
                    'created_at' => $org->created_at,
                    'stats' => [
                        'users_count' => $org->users()->count(),
                        'projects_count' => $org->projects()->count(),
                        'contracts_count' => $org->contracts()->count(),
                        'active_contracts_value' => $org->contracts()->where('status', 'active')->sum('total_amount'),
                    ],
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $organizations
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении списка организаций',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getOrganizationData(string $slug, int $organizationId, Request $request): JsonResponse
    {
        try {
            $group = OrganizationGroup::where('slug', $slug)->first();
            
            if (!$group) {
                return response()->json([
                    'success' => false,
                    'message' => 'Холдинг не найден'
                ], 404);
            }

            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Необходима авторизация'
                ], 401);
            }

            $organizationData = $this->multiOrganizationService->getOrganizationData($organizationId, $user);

            return response()->json([
                'success' => true,
                'data' => $organizationData
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении данных организации',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 