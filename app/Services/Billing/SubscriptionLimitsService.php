<?php

namespace App\Services\Billing;

use App\Models\User;
use App\Models\UserSubscription;
use App\Models\SubscriptionPlan;
use App\Services\Billing\UserSubscriptionService;
use App\Interfaces\Billing\SubscriptionLimitsServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Repositories\Landing\OrganizationSubscriptionRepository;
use App\Models\OrganizationSubscription;

class SubscriptionLimitsService implements SubscriptionLimitsServiceInterface
{
    protected UserSubscriptionService $subscriptionService;
    protected OrganizationSubscriptionRepository $organizationSubscriptionRepo;

    public function __construct(UserSubscriptionService $subscriptionService, OrganizationSubscriptionRepository $organizationSubscriptionRepo)
    {
        $this->subscriptionService = $subscriptionService;
        $this->organizationSubscriptionRepo = $organizationSubscriptionRepo;
    }

    public function getUserLimitsData(User $user): array
    {
        // 1) Сначала смотрим подписку организации, так как она общая для всех пользователей.
        $organizationId = $user->current_organization_id;
        if ($organizationId) {
            $orgSubscription = $this->organizationSubscriptionRepo->getByOrganizationId($organizationId);
            if ($orgSubscription && $orgSubscription->status === 'active') {
                return $this->getOrganizationLimitsData($user, $orgSubscription);
            }
        }

        // 2) Если активной организации-подписки нет, используем персональную
        $userSubscription = $this->subscriptionService->getUserCurrentValidSubscription($user);
        if ($userSubscription) {
            return $this->getSubscriptionLimitsData($user, $userSubscription);
        }

        // 3) Ни того, ни другого — отдаём базовые лимиты
        return $this->getDefaultLimitsData($user);
    }

    private function getSubscriptionLimitsData(User $user, UserSubscription $subscription): array
    {
        $plan = $subscription->plan;
        $currentUsage = $this->getCurrentUsage($user);

        return [
            'has_subscription' => true,
            'subscription' => [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'plan_name' => $plan->name,
                'plan_description' => $plan->description,
                'is_trial' => $subscription->isOnTrial(),
                'trial_ends_at' => $subscription->trial_ends_at?->format('Y-m-d H:i:s'),
                'ends_at' => $subscription->ends_at?->format('Y-m-d H:i:s'),
                'next_billing_at' => $subscription->next_billing_at?->format('Y-m-d H:i:s'),
                'is_canceled' => $subscription->isCanceled(),
            ],
            'limits' => [
                'foremen' => $this->formatLimitData($plan->max_foremen, $currentUsage['foremen']),
                'projects' => $this->formatLimitData($plan->max_projects, $currentUsage['projects']),
                'users' => $this->formatLimitData($plan->max_users, $currentUsage['users']),
                'storage' => $this->formatStorageLimitData($plan->max_storage_gb, $currentUsage['storage_mb']),
            ],
            'features' => $plan->features ? (array) $plan->features : [],
            'warnings' => $this->generateWarnings($plan, $currentUsage),
        ];
    }

    private function getDefaultLimitsData(User $user): array
    {
        $defaultLimits = config('billing.default_limits');
        $currentUsage = $this->getCurrentUsage($user);

        return [
            'has_subscription' => false,
            'subscription' => null,
            'limits' => [
                'foremen' => $this->formatLimitData($defaultLimits['max_users'] ?? 1, $currentUsage['foremen']),
                'projects' => $this->formatLimitData($defaultLimits['max_projects'] ?? 1, $currentUsage['projects']),
                'storage' => $this->formatStorageLimitData(
                    round(($defaultLimits['max_storage_mb'] ?? 100) / 1024, 2),
                    $currentUsage['storage_mb']
                ),
            ],
            'features' => [],
            'warnings' => $this->generateDefaultWarnings($defaultLimits, $currentUsage),
            'upgrade_required' => true,
        ];
    }

    private function formatLimitData(?int $limit, int $used): array
    {
        if (is_null($limit)) {
            return [
                'limit' => null,
                'used' => $used,
                'remaining' => null,
                'percentage_used' => 0,
                'is_unlimited' => true,
            ];
        }

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'percentage_used' => $limit > 0 ? round(($used / $limit) * 100, 1) : 0,
            'is_unlimited' => false,
        ];
    }

    private function formatStorageLimitData(?float $limitGb, float $usedMb): array
    {
        if (is_null($limitGb)) {
            return [
                'limit_gb' => null,
                'used_gb' => round($usedMb / 1024, 2),
                'remaining_gb' => null,
                'percentage_used' => 0,
                'is_unlimited' => true,
            ];
        }

        $usedGb = round($usedMb / 1024, 2);
        $remainingGb = max(0, round($limitGb - $usedGb, 2));

        return [
            'limit_gb' => $limitGb,
            'used_gb' => $usedGb,
            'remaining_gb' => $remainingGb,
            'percentage_used' => $limitGb > 0 ? round(($usedGb / $limitGb) * 100, 1) : 0,
            'is_unlimited' => false,
        ];
    }

    public function getCurrentUsage(User $user): array
    {
        $organizationId = $user->current_organization_id;
        
        $cacheKey = "user_usage_{$user->id}_{$organizationId}";
        
        return Cache::remember($cacheKey, 300, function () use ($user, $organizationId) {
            return [
                'foremen' => $this->getForemenCount($organizationId),
                'projects' => $this->getProjectsCount($organizationId),
                'users' => $this->getUsersCount($organizationId),
                'storage_mb' => $this->getStorageUsage($organizationId),
            ];
        });
    }

    private function getForemenCount(int $organizationId): int
    {
        return DB::table('role_user')
            ->join('roles', 'role_user.role_id', '=', 'roles.id')
            ->where('role_user.organization_id', $organizationId)
            ->where('roles.slug', 'foreman')
            ->count();
    }

    private function getProjectsCount(int $organizationId): int
    {
        return \App\Models\Project::where('organization_id', $organizationId)->count();
    }

    private function getUsersCount(int $organizationId): int
    {
        return DB::table('organization_user')
            ->where('organization_id', $organizationId)
            ->count();
    }

    private function getStorageUsage(int $organizationId): float
    {
        $org = \App\Models\Organization::find($organizationId);
        if ($org && !is_null($org->storage_used_mb)) {
            return (float) $org->storage_used_mb;
        }

        // Fallback на старую эвристику, если счётчик ещё не посчитан
        $completedWorksCount = \App\Models\CompletedWork::whereHas('contract', function($query) use ($organizationId) {
            $query->where('organization_id', $organizationId);
        })->count();
        
        $materialsCount = \App\Models\Material::where('organization_id', $organizationId)->count();
        
        return ($completedWorksCount * 0.1) + ($materialsCount * 0.05);
    }

    public function canCreateUser(User $user): bool
    {
        $limitsData = $this->getUserLimitsData($user);
        
        if (!$limitsData['has_subscription']) {
            $defaultLimits = config('billing.default_limits');
            $currentUsage = $this->getCurrentUsage($user);
            return $currentUsage['users'] < ($defaultLimits['max_users'] ?? 1);
        }
        
        $userLimit = $limitsData['limits']['users'];
        return $userLimit['is_unlimited'] || $userLimit['used'] < $userLimit['limit'];
    }

    public function canCreateForeman(User $user): bool
    {
        $limitsData = $this->getUserLimitsData($user);
        
        if (!$limitsData['has_subscription']) {
            $defaultLimits = config('billing.default_limits');
            $currentUsage = $this->getCurrentUsage($user);
            return $currentUsage['foremen'] < ($defaultLimits['max_foremen'] ?? 1);
        }
        
        $foremanLimit = $limitsData['limits']['foremen'];
        return $foremanLimit['is_unlimited'] || $foremanLimit['used'] < $foremanLimit['limit'];
    }

    public function canCreateProject(User $user): bool
    {
        $limitsData = $this->getUserLimitsData($user);
        
        if (!$limitsData['has_subscription']) {
            $defaultLimits = config('billing.default_limits');
            $currentUsage = $this->getCurrentUsage($user);
            return $currentUsage['projects'] < ($defaultLimits['max_projects'] ?? 1);
        }
        
        $projectLimit = $limitsData['limits']['projects'];
        return $projectLimit['is_unlimited'] || $projectLimit['used'] < $projectLimit['limit'];
    }

    private function generateWarnings(SubscriptionPlan $plan, array $currentUsage): array
    {
        $warnings = [];
        
        if ($plan->max_foremen && $currentUsage['foremen'] >= $plan->max_foremen * 0.8) {
            $warnings[] = [
                'type' => 'foremen',
                'level' => $currentUsage['foremen'] >= $plan->max_foremen ? 'critical' : 'warning',
                'message' => $currentUsage['foremen'] >= $plan->max_foremen 
                    ? 'Достигнут лимит количества прорабов'
                    : 'Приближаетесь к лимиту количества прорабов',
            ];
        }
        
        if ($plan->max_projects && $currentUsage['projects'] >= $plan->max_projects * 0.8) {
            $warnings[] = [
                'type' => 'projects',
                'level' => $currentUsage['projects'] >= $plan->max_projects ? 'critical' : 'warning',
                'message' => $currentUsage['projects'] >= $plan->max_projects 
                    ? 'Достигнут лимит количества проектов'
                    : 'Приближаетесь к лимиту количества проектов',
            ];
        }
        
        $storageLimit = $plan->max_storage_gb * 1024;
        if ($storageLimit && $currentUsage['storage_mb'] >= $storageLimit * 0.8) {
            $warnings[] = [
                'type' => 'storage',
                'level' => $currentUsage['storage_mb'] >= $storageLimit ? 'critical' : 'warning',
                'message' => $currentUsage['storage_mb'] >= $storageLimit 
                    ? 'Достигнут лимит дискового пространства'
                    : 'Приближаетесь к лимиту дискового пространства',
            ];
        }
        
        return $warnings;
    }

    private function generateDefaultWarnings(array $defaultLimits, array $currentUsage): array
    {
        $warnings = [];
        
        if ($currentUsage['foremen'] >= ($defaultLimits['max_users'] ?? 1)) {
            $warnings[] = [
                'type' => 'foremen',
                'level' => 'critical',
                'message' => 'Достигнут лимит бесплатного тарифа. Оформите подписку для добавления прорабов.',
            ];
        }
        
        if ($currentUsage['projects'] >= ($defaultLimits['max_projects'] ?? 1)) {
            $warnings[] = [
                'type' => 'projects',
                'level' => 'critical',
                'message' => 'Достигнут лимит бесплатного тарифа. Оформите подписку для создания новых проектов.',
            ];
        }
        
        if ($currentUsage['storage_mb'] >= ($defaultLimits['max_storage_mb'] ?? 100)) {
            $warnings[] = [
                'type' => 'storage',
                'level' => 'critical',
                'message' => 'Достигнут лимит дискового пространства бесплатного тарифа. Оформите подписку.',
            ];
        }
        
        return $warnings;
    }

    public function clearUserUsageCache(User $user): void
    {
        $organizationId = $user->current_organization_id;
        $cacheKey = "user_usage_{$user->id}_{$organizationId}";
        Cache::forget($cacheKey);
    }

    /**
     * Формируем данные лимитов на основе подписки организации
     */
    private function getOrganizationLimitsData(User $user, OrganizationSubscription $subscription): array
    {
        $plan = $subscription->plan;
        $currentUsage = $this->getCurrentUsage($user);

        return [
            'has_subscription' => true,
            'subscription' => [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'plan_name' => $plan->name,
                'plan_description' => $plan->description,
                'is_trial' => false,
                'trial_ends_at' => $subscription->trial_ends_at?->format('Y-m-d H:i:s'),
                'ends_at' => $subscription->ends_at?->format('Y-m-d H:i:s'),
                'next_billing_at' => $subscription->next_billing_at?->format('Y-m-d H:i:s'),
                'is_canceled' => $subscription->canceled_at !== null,
            ],
            'limits' => [
                'foremen' => $this->formatLimitData($plan->max_foremen, $currentUsage['foremen']),
                'projects' => $this->formatLimitData($plan->max_projects, $currentUsage['projects']),
                'users' => $this->formatLimitData($plan->max_users, $currentUsage['users']),
                'storage' => $this->formatStorageLimitData($plan->max_storage_gb, $currentUsage['storage_mb']),
            ],
            'features' => $plan->features ? (array) $plan->features : [],
            'warnings' => $this->generateWarnings($plan, $currentUsage),
        ];
    }
} 