<?php

namespace App\Services\Billing;

use App\Models\User;
use App\Models\SubscriptionPlan;
use App\Interfaces\Billing\SubscriptionLimitsServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Repositories\Landing\OrganizationSubscriptionRepository;
use App\Models\OrganizationSubscription;

class SubscriptionLimitsService implements SubscriptionLimitsServiceInterface
{
    protected OrganizationSubscriptionRepository $organizationSubscriptionRepo;

    public function __construct(OrganizationSubscriptionRepository $organizationSubscriptionRepo)
    {
        $this->organizationSubscriptionRepo = $organizationSubscriptionRepo;
    }

    public function getUserLimitsData(User $user): array
    {
        // КРИТИЧНО: Кешируем весь результат более агрессивно + timeout + fallback
        $cacheKey = "user_limits_full_{$user->id}_{$user->current_organization_id}";
        
        try {
            return \Illuminate\Support\Facades\Cache::remember($cacheKey, 600, function () use ($user) {
                // Timeout для операции
                $startTime = microtime(true);
                $timeoutSeconds = 3;
                
                try {
                    // Смотрим только подписку организации
                    $organizationId = $user->current_organization_id;
                    if ($organizationId) {
                        // Проверяем timeout
                        if ((microtime(true) - $startTime) > $timeoutSeconds) {
                            throw new \Exception('Timeout getting subscription');
                        }
                        
                        $orgSubscription = $this->organizationSubscriptionRepo->getByOrganizationId($organizationId);
                        // Подписка активна, если не истекла (даже если отменена, но срок еще не закончился)
                        if ($orgSubscription && $orgSubscription->status === 'active' && $orgSubscription->ends_at > now()) {
                            return $this->getOrganizationLimitsData($user, $orgSubscription);
                        }
                    }

                    // Если нет активной организационной подписки — отдаём базовые лимиты
                    return $this->getDefaultLimitsData($user);
                    
                } catch (\Exception $e) {
                    // Fallback на базовые лимиты при любых ошибках
                    return $this->getFallbackLimitsData($user);
                }
            });
        } catch (\Exception $e) {
            // Если кеш недоступен - сразу fallback
            return $this->getFallbackLimitsData($user);
        }
    }
    
    /**
     * Быстрые базовые лимиты без запросов к БД
     */
    private function getFallbackLimitsData(User $user): array
    {
        return [
            'has_subscription' => false,
            'subscription' => null,
            'limits' => [
                'foremen' => ['limit' => 1, 'used' => 0, 'remaining' => 1, 'percentage_used' => 0, 'is_unlimited' => false],
                'projects' => ['limit' => 1, 'used' => 0, 'remaining' => 1, 'percentage_used' => 0, 'is_unlimited' => false],
                'storage' => ['limit_gb' => 0.1, 'used_gb' => 0, 'used_mb' => 0, 'remaining_gb' => 0.1, 'percentage_used' => 0, 'is_unlimited' => false],
            ],
            'features' => [],
            'warnings' => [],
            'upgrade_required' => true,
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
                'used_mb' => round($usedMb, 2),
                'remaining_gb' => null,
                'percentage_used' => 0,
                'is_unlimited' => true,
            ];
        }

        $usedGb = round($usedMb / 1024, 4);
        $remainingGb = max(0, round($limitGb - $usedGb, 2));

        return [
            'limit_gb' => $limitGb,
            'used_gb' => $usedGb,
            'used_mb' => round($usedMb, 2),
            'remaining_gb' => $remainingGb,
            'percentage_used' => $limitGb > 0 ? round(($usedGb / $limitGb) * 100, 2) : 0,
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
                'contractor_invitations' => $this->getContractorInvitationsUsage($organizationId),
            ];
        });
    }

    private function getForemenCount(int $organizationId): int
    {
        // Используем новую систему авторизации
        $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);
        
        return \App\Domain\Authorization\Models\UserRoleAssignment::where('context_id', $context->id)
            ->where('role_slug', 'foreman')
            ->where('is_active', true)
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
        // КРИТИЧНО: Кешируем storage usage на 10 минут, т.к. он редко меняется
        return \Illuminate\Support\Facades\Cache::remember("storage_usage_{$organizationId}", 600, function () use ($organizationId) {
            $org = \App\Models\Organization::find($organizationId);
            if ($org && !is_null($org->storage_used_mb)) {
                return (float) $org->storage_used_mb;
            }

            // Fallback на старую эвристику, если счётчик ещё не посчитан
            // ОПТИМИЗАЦИЯ: Используем более быстрые запросы через JOIN вместо whereHas
            try {
                $completedWorksCount = \Illuminate\Support\Facades\DB::table('completed_works')
                    ->join('contracts', 'completed_works.contract_id', '=', 'contracts.id')
                    ->where('contracts.organization_id', $organizationId)
                    ->count();
                
                $materialsCount = \App\Models\Material::where('organization_id', $organizationId)->count();
                
                return ($completedWorksCount * 0.1) + ($materialsCount * 0.05);
            } catch (\Exception $e) {
                // Если запросы не работают, возвращаем минимальное значение
                return 0.1;
            }
        });
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

    public function clearAllSubscriptionCache(int $userId, int $organizationId): void
    {
        Cache::forget("user_limits_full_{$userId}_{$organizationId}");
        Cache::forget("subscription_limits_{$userId}_{$organizationId}");
        Cache::forget("user_usage_{$userId}_{$organizationId}");
        Cache::forget("storage_usage_{$organizationId}");
    }

    public function clearOrganizationSubscriptionCache(int $organizationId): void
    {
        $pattern = "user_limits_full_*_{$organizationId}";
        $patternLimits = "subscription_limits_*_{$organizationId}";
        $patternUsage = "user_usage_*_{$organizationId}";
        
        Cache::forget("storage_usage_{$organizationId}");
        
        if (method_exists(Cache::getStore(), 'flush')) {
            return;
        }
    }

    /**
     * Формируем данные лимитов на основе подписки организации
     */
    public function canCreateContractorInvitation(User $user): bool
    {
        $limitsData = $this->getUserLimitsData($user);
        
        if (!$limitsData['has_subscription']) {
            $defaultLimits = config('billing.default_limits');
            $currentUsage = $this->getCurrentUsage($user);
            return $currentUsage['contractor_invitations'] < ($defaultLimits['max_contractor_invitations'] ?? 5);
        }
        
        $invitationLimit = $limitsData['limits']['contractor_invitations'];
        return $invitationLimit['is_unlimited'] || $invitationLimit['used'] < $invitationLimit['limit'];
    }

    public function getContractorInvitationsUsage(int $organizationId): int
    {
        return DB::table('contractor_invitations')
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['pending', 'accepted'])
            ->where('created_at', '>=', now()->subMonth())
            ->count();
    }

    public function getRemainingContractorInvitations(User $user): int
    {
        $limitsData = $this->getUserLimitsData($user);
        
        if (!$limitsData['has_subscription']) {
            $defaultLimits = config('billing.default_limits');
            $currentUsage = $this->getCurrentUsage($user);
            return max(0, ($defaultLimits['max_contractor_invitations'] ?? 5) - $currentUsage['contractor_invitations']);
        }
        
        $invitationLimit = $limitsData['limits']['contractor_invitations'];
        if ($invitationLimit['is_unlimited']) {
            return 999999;
        }
        
        return max(0, $invitationLimit['limit'] - $invitationLimit['used']);
    }

    private function generateSubscriptionWarnings(OrganizationSubscription $subscription, SubscriptionPlan $plan, array $currentUsage): array
    {
        $warnings = $this->generateWarnings($plan, $currentUsage);
        
        // Добавляем предупреждение об отмененной подписке
        if ($subscription->isCanceled()) {
            $warnings[] = [
                'type' => 'subscription_canceled',
                'level' => 'warning',
                'message' => 'Подписка отменена и закончится ' . $subscription->ends_at->format('d.m.Y') . '. Автопродление отключено.',
            ];
        }
        
        // Предупреждение о скором окончании подписки
        $daysLeft = now()->diffInDays($subscription->ends_at, false);
        if ($daysLeft <= 7 && $daysLeft > 0 && !$subscription->isCanceled()) {
            $warnings[] = [
                'type' => 'subscription_expiring',
                'level' => $daysLeft <= 3 ? 'critical' : 'warning',
                'message' => $daysLeft <= 3 
                    ? "Подписка заканчивается через {$daysLeft} дн. Пополните баланс для автопродления."
                    : "Подписка заканчивается через {$daysLeft} дн.",
            ];
        }
        
        return $warnings;
    }

    private function getOrganizationLimitsData(User $user, OrganizationSubscription $subscription): array
    {
        $plan = $subscription->plan;
        $currentUsage = $this->getCurrentUsage($user);

        return [
            'has_subscription' => true,
            'subscription' => [
                'id' => $subscription->id,
                'status' => $subscription->getEffectiveStatus(),
                'plan_name' => $plan->name,
                'plan_description' => $plan->description,
                'is_trial' => false,
                'trial_ends_at' => $subscription->trial_ends_at?->format('Y-m-d H:i:s'),
                'ends_at' => $subscription->ends_at?->format('Y-m-d H:i:s'),
                'next_billing_at' => $subscription->next_billing_at?->format('Y-m-d H:i:s'),
                'is_canceled' => $subscription->isCanceled(),
                'canceled_at' => $subscription->canceled_at?->format('Y-m-d H:i:s'),
                'is_auto_payment_enabled' => $subscription->is_auto_payment_enabled,
                'upgrade_required' => false,
            ],
            'limits' => [
                'foremen' => $this->formatLimitData($plan->max_foremen, $currentUsage['foremen']),
                'projects' => $this->formatLimitData($plan->max_projects, $currentUsage['projects']),
                'users' => $this->formatLimitData($plan->max_users, $currentUsage['users']),
                'storage' => $this->formatStorageLimitData($plan->max_storage_gb, $currentUsage['storage_mb']),
                'contractor_invitations' => $this->formatLimitData($plan->max_contractor_invitations ?? null, $currentUsage['contractor_invitations']),
            ],
            'features' => $plan->features ? (array) $plan->features : [],
            'warnings' => $this->generateSubscriptionWarnings($subscription, $plan, $currentUsage),
        ];
    }
} 