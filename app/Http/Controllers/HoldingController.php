<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\OrganizationGroup;
use App\Services\Landing\MultiOrganizationService;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use Illuminate\Support\Facades\Auth;

class HoldingController extends Controller
{
    protected MultiOrganizationService $multiOrgService;

    public function __construct(MultiOrganizationService $multiOrgService)
    {
        $this->multiOrgService = $multiOrgService;
    }

    public function getSiteData(Request $request)
    {
        $holding = $request->attributes->get('holding');
        
        if (!$holding) {
            abort(404, 'Холдинг не найден');
        }

        $site = HoldingSite::where('organization_group_id', $holding->id)
            ->where('is_active', true)
            ->first();

        if (!$site) {
            return response()->json([
                'success' => false,
                'message' => 'Лендинг холдинга еще не создан',
                'data' => null
            ], 404);
        }

        if (!$site->isPublished() && !$this->isValidPreview($request, $site)) {
            return response()->json([
                'success' => false,
                'message' => 'Лендинг не опубликован',
                'data' => null
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $site->getFullSiteData()
        ]);
    }

    public function index(Request $request)
    {
        $holding = $request->attributes->get('holding');
        
        if (!$holding) {
            abort(404, 'Холдинг не найден');
        }

        $data = [
            'holding' => $holding,
            'parent_organization' => $holding->parentOrganization,
            'stats' => $this->getHoldingStats($holding),
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
            'view' => 'holding.dashboard'
        ]);
    }

    public function dashboard(Request $request)
    {
        $user = Auth::user();
        $holding = $request->attributes->get('holding');
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Необходима авторизация',
                'redirect' => '/login'
            ], 401);
        }

        if (!$this->multiOrgService->hasAccessToOrganization($user, $holding->parent_organization_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Нет доступа к данному холдингу'
            ], 403);
        }

        $hierarchy = $this->multiOrgService->getOrganizationHierarchy($holding->parent_organization_id);
        
        return response()->json([
            'success' => true,
            'data' => [
                'holding' => $holding,
                'hierarchy' => $hierarchy,
                'user' => $user,
                'consolidated_stats' => $this->getConsolidatedStats($holding),
            ]
        ]);
    }

    public function childOrganizations(Request $request)
    {
        $user = Auth::user();
        $holding = $request->attributes->get('holding');
        
        if (!$this->multiOrgService->hasAccessToOrganization($user, $holding->parent_organization_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Нет доступа к данному холдингу'
            ], 403);
        }

        $childOrganizations = $holding->parentOrganization->childOrganizations()
            ->with(['users', 'projects', 'contracts'])
            ->get()
            ->map(function ($org) {
                return [
                    'id' => $org->id,
                    'name' => $org->name,
                    'description' => $org->description,
                    'created_at' => $org->created_at,
                    'stats' => [
                        'users_count' => $org->users()->count(),
                        'projects_count' => $org->projects()->count(),
                        'contracts_count' => $org->contracts()->count(),
                        'active_contracts_value' => $org->contracts()
                            ->where('status', 'active')
                            ->sum('total_amount'),
                    ]
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $childOrganizations
        ]);
    }

    private function getHoldingStats(OrganizationGroup $holding): array
    {
        $parentOrg = $holding->parentOrganization;
        $childOrgs = $parentOrg->childOrganizations;

        return [
            'total_child_organizations' => $childOrgs->count(),
            'total_users' => $childOrgs->sum(fn($org) => $org->users()->count()) + $parentOrg->users()->count(),
            'total_projects' => $childOrgs->sum(fn($org) => $org->projects()->count()) + $parentOrg->projects()->count(),
            'total_contracts_value' => $childOrgs->sum(fn($org) => $org->contracts()->sum('total_amount')) + $parentOrg->contracts()->sum('total_amount'),
            'active_contracts_count' => $childOrgs->sum(fn($org) => $org->contracts()->where('status', 'active')->count()) + $parentOrg->contracts()->where('status', 'active')->count(),
        ];
    }

    private function getConsolidatedStats(OrganizationGroup $holding): array
    {
        $stats = $this->getHoldingStats($holding);
        
        $recentActivity = $this->getRecentActivity($holding);
        
        return array_merge($stats, [
            'recent_activity' => $recentActivity,
            'performance_metrics' => $this->getPerformanceMetrics($holding),
        ]);
    }

    private function getRecentActivity(OrganizationGroup $holding): array
    {
        return [];
    }

    private function getPerformanceMetrics(OrganizationGroup $holding): array
    {
        return [
            'monthly_growth' => 0,
            'efficiency_score' => 0,
            'satisfaction_index' => 0,
        ];
    }

    private function isValidPreview(Request $request, HoldingSite $site): bool
    {
        if (!$request->has('preview') || $request->get('preview') !== 'true') {
            return false;
        }

        $token = $request->get('token');
        if (!$token) {
            return false;
        }

        return $site->isValidPreviewToken($token);
    }
} 