<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\User;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // Политики для новой системы авторизации определяются через AuthorizationService
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

        Gate::before(function (\App\Models\User $user, string $ability, $arguments = null) {
            $userAgent = request()->userAgent() ?? '';
            if (str_contains($userAgent, 'Prometheus')) {
                return null;
            }
            
            $authorizationService = app(\App\Domain\Authorization\Services\AuthorizationService::class);
            
            if (strpos($ability, ':') !== false) {
                [$permission, $context] = explode(':', $ability, 2);
                
                $organizationId = null;
                if (is_numeric($context)) {
                    $organizationId = (int) $context;
                } elseif ($arguments && is_array($arguments) && isset($arguments['organization_id'])) {
                    $organizationId = $arguments['organization_id'];
                } elseif ($user->current_organization_id) {
                    $organizationId = $user->current_organization_id;
                }
                
                if ($organizationId) {
                    return $authorizationService->can($user, $permission, ['context_type' => 'organization', 'context_id' => $organizationId]);
                }
            }
            
            if (str_starts_with($ability, 'admin.')) {
                $organizationAccess = false;
                if ($user->current_organization_id) {
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
    }
}
