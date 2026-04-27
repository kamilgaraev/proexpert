<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Services\Security\SystemAdminRoleService;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        \App\Models\Estimate::class => \App\BusinessModules\Features\BudgetEstimates\Policies\EstimatePolicy::class,
        \App\Models\ProjectSchedule::class => \App\Policies\ProjectSchedulePolicy::class,
        \App\Models\ConstructionJournal::class => \App\Policies\ConstructionJournalPolicy::class,
        \App\Models\ConstructionJournalEntry::class => \App\Policies\ConstructionJournalEntryPolicy::class,
        \App\Models\Blog\BlogArticle::class => \App\Policies\SystemAdmin\BlogArticlePolicy::class,
        \App\Models\Blog\BlogCategory::class => \App\Policies\SystemAdmin\BlogCategoryPolicy::class,
        \App\Models\Blog\BlogTag::class => \App\Policies\SystemAdmin\BlogTagPolicy::class,
        \App\Models\Blog\BlogComment::class => \App\Policies\SystemAdmin\BlogCommentPolicy::class,
        \App\Models\Blog\BlogMediaAsset::class => \App\Policies\SystemAdmin\BlogMediaAssetPolicy::class,
        \App\Models\Blog\BlogArticleRevision::class => \App\Policies\SystemAdmin\BlogArticleRevisionPolicy::class,
        \App\Models\Blog\BlogSeoSettings::class => \App\Policies\SystemAdmin\BlogSeoSettingsPolicy::class,
        \App\BusinessModules\Features\Notifications\Models\NotificationTemplate::class => \App\Policies\SystemAdmin\NotificationTemplatePolicy::class,
        \App\BusinessModules\Features\Notifications\Models\Notification::class => \App\Policies\SystemAdmin\NotificationPolicy::class,
        \App\BusinessModules\Features\Notifications\Models\NotificationAnalytics::class => \App\Policies\SystemAdmin\NotificationAnalyticsPolicy::class,
    ];

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        Gate::before(function ($user, string $ability, $arguments = null) {
            if ($user instanceof SystemAdmin) {
                if (!$user->isActive()) {
                    return false;
                }

                if ($user->isSuperAdmin()) {
                    return true;
                }

                if (str_starts_with($ability, 'system_admin.')) {
                    return app(SystemAdminRoleService::class)->hasPermission($user, $ability);
                }

                return null;
            }

            $userAgent = request()->userAgent() ?? '';
            if (str_contains($userAgent, 'Prometheus')) {
                return null;
            }
            
            // Пропускаем специальные Gates с кастомной логикой - пусть обрабатываются в Gate::define()
            $customGates = ['access-mobile-app', 'organization.manage', 'admin.access'];
            if (in_array($ability, $customGates)) {
                return null;
            }
            
            // Стандартные Policy abilities (view, create, update, delete, etc) - всегда пропускаем в Policy
            $standardPolicyAbilities = ['viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete', 'approve', 'import', 'export'];
            if (in_array($ability, $standardPolicyAbilities)) {
                // Если есть объект модели или класс модели в arguments - пропускаем в Policy
                if (!empty($arguments)) {
                    if (is_object($arguments[0])) {
                        $modelClass = get_class($arguments[0]);
                        // Пропускаем в Policy только если Policy зарегистрирован для этого класса модели
                        if (isset($this->policies[$modelClass])) {
                            return null; // Пропускаем в Policy
                        }
                    }
                    if (is_string($arguments[0]) && isset($this->policies[$arguments[0]])) {
                        return null; // Пропускаем в Policy
                    }
                    if (is_array($arguments)) {
                        foreach ($arguments as $arg) {
                            if (is_object($arg)) {
                                $modelClass = get_class($arg);
                                if (isset($this->policies[$modelClass])) {
                                    return null; // Пропускаем в Policy
                                }
                            }
                        }
                    }
                }
                // Если нет объекта модели, но это стандартная Policy ability - все равно пропускаем в Policy
                // (Policy может проверить права без модели, например для create)
                return null;
            }
            
            // Пропускаем model policy abilities (view, create, update, delete, etc)
            // чтобы они обрабатывались через зарегистрированные Policy классы
            // Эта логика обрабатывает случаи, когда ability не является стандартной Policy ability
            if (!empty($arguments)) {
                // Если первый аргумент - объект модели, проверяем, зарегистрирован ли Policy
                if (is_object($arguments[0])) {
                    $modelClass = get_class($arguments[0]);
                    if (isset($this->policies[$modelClass])) {
                        return null; // Пропускаем в Policy
                    }
                }
                
                // Если первый аргумент - строка класса модели, зарегистрированная в policies, пропускаем в Policy
                if (is_string($arguments[0]) && isset($this->policies[$arguments[0]])) {
                    return null;
                }
                
                // Если arguments - массив и содержит объект модели, пропускаем в Policy
                if (is_array($arguments)) {
                    foreach ($arguments as $arg) {
                        if (is_object($arg)) {
                            $modelClass = get_class($arg);
                            if (isset($this->policies[$modelClass])) {
                                return null; // Пропускаем в Policy
                            }
                        }
                    }
                }
            }
            
            $authorizationService = app(\App\Domain\Authorization\Services\AuthorizationService::class);
            
            if (strpos($ability, ':') !== false) {
                [$permission, $context] = explode(':', $ability, 2);
                
                $organizationId = null;
                if (is_numeric($context)) {
                    $organizationId = (int) $context;
                } elseif ($arguments && is_array($arguments) && isset($arguments['organization_id'])) {
                    $organizationId = $arguments['organization_id'];
                } elseif (isset($user->current_organization_id) && $user->current_organization_id) {
                    $organizationId = $user->current_organization_id;
                }
                
                if ($organizationId) {
                    return $authorizationService->can($user, $permission, ['context_type' => 'organization', 'context_id' => $organizationId]);
                }
            }
            
            if (str_starts_with($ability, 'admin.')) {
                $organizationAccess = false;
                if (isset($user->current_organization_id) && $user->current_organization_id) {
                    $organizationAccess = $authorizationService->can($user, $ability, [
                        'context_type' => 'organization', 
                        'organization_id' => $user->current_organization_id
                    ]);
                }
                
                $systemAccess = $authorizationService->can($user, $ability, ['context_type' => 'system']);
                
                return $organizationAccess || $systemAccess;
            }
            
            return $authorizationService->can($user, $ability, ['context_type' => 'system']);
        });
        
        // Перехватываем результат Policy, чтобы не проверять право в системном контексте, если Policy уже вернул результат
        Gate::after(function ($user, string $ability, $result, $arguments = null) {
            if ($user instanceof SystemAdmin) {
                return $result;
            }

            $userAgent = request()->userAgent() ?? '';
            if (str_contains($userAgent, 'Prometheus')) {
                return null;
            }
            
            // Если это стандартная Policy ability с объектом модели, и Policy вернул результат (true или false),
            // не позволяем Gate проверять право в системном контексте
            $standardPolicyAbilities = ['viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete', 'approve', 'import', 'export'];
            if (in_array($ability, $standardPolicyAbilities) && !empty($arguments)) {
                if (is_object($arguments[0])) {
                    $modelClass = get_class($arguments[0]);
                    if (isset($this->policies[$modelClass])) {
                        // Policy уже обработал запрос, возвращаем его результат
                        return $result;
                    }
                }
            }
            
            return null; // Продолжаем стандартную обработку
        });
        
        Gate::define('admin.access', function (User $user) {
            $userAgent = request()->userAgent() ?? '';
            if (str_contains($userAgent, 'Prometheus')) {
                return false;
            }
            
            $authorizationService = app(\App\Domain\Authorization\Services\AuthorizationService::class);
            return $authorizationService->can($user, 'admin.access', ['context_type' => 'system']) ||
                   $authorizationService->can($user, 'admin.access', ['context_type' => 'organization', 'context_id' => $user->current_organization_id]);
        });
        
        Gate::define('organization.manage', function (User $user, ?int $organizationId = null) {
            $userAgent = request()->userAgent() ?? '';
            if (str_contains($userAgent, 'Prometheus')) {
                return false;
            }
            
            $authorizationService = app(\App\Domain\Authorization\Services\AuthorizationService::class);
            $orgId = $organizationId ?? $user->current_organization_id;
            return $authorizationService->can($user, 'organization.manage', ['context_type' => 'organization', 'context_id' => $orgId]);
        });
        
        Gate::define('access-mobile-app', function (User $user, ?int $organizationId = null) {
            $mobileAccessHelper = app(\App\Helpers\MobileAccessHelper::class);
            $orgId = $organizationId ?? $user->current_organization_id;
            
            if (!$orgId) {
                Log::warning('[Gate:access-mobile-app] No organization ID', ['user_id' => $user->id]);
                return false;
            }
            
            $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($orgId);
            
            if (!$context) {
                Log::warning('[Gate:access-mobile-app] Context not found', ['user_id' => $user->id, 'org_id' => $orgId]);
                return false;
            }
            
            $userRoles = $user->roleAssignments()
                ->where('context_id', $context->id)
                ->where('is_active', true)
                ->pluck('role_slug')
                ->toArray();
            
            Log::info('[Gate:access-mobile-app] User roles check', [
                'user_id' => $user->id,
                'org_id' => $orgId,
                'context_id' => $context->id,
                'roles' => $userRoles
            ]);
            
            if (empty($userRoles)) {
                Log::warning('[Gate:access-mobile-app] No roles found', ['user_id' => $user->id, 'org_id' => $orgId, 'context_id' => $context->id]);
                return false;
            }
            
            foreach ($userRoles as $roleSlug) {
                $hasAccess = $mobileAccessHelper->canRoleAccessMobile($roleSlug, $orgId);
                Log::info('[Gate:access-mobile-app] Role check', [
                    'role' => $roleSlug,
                    'has_mobile_access' => $hasAccess
                ]);
                
                if ($hasAccess) {
                    Log::info('[Gate:access-mobile-app] Access GRANTED', ['user_id' => $user->id, 'role' => $roleSlug]);
                    return true;
                }
            }
            
            Log::warning('[Gate:access-mobile-app] Access DENIED - no role with mobile access', [
                'user_id' => $user->id,
                'org_id' => $orgId,
                'roles_checked' => $userRoles
            ]);
            
            return false;
        });
    }
}
