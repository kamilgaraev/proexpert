<?php

namespace App\Domain\Authorization\Http\Middleware;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Models\AuthorizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

/**
 * Middleware для проверки ролей пользователя
 * 
 * Использование:
 * Route::middleware('role:organization_admin')->get('/admin', ...);
 * Route::middleware('role:project_manager,organization')->get('/projects', ...);
 * Route::middleware('role:foreman|worker,project,project_id')->get('/mobile', ...);
 */
class RoleMiddleware
{
    protected AuthorizationService $authService;

    public function __construct(AuthorizationService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string $roles Роли (разделенные | для OR, , для AND)
     * @param string|null $contextType Тип контекста
     * @param string|null $contextParam Параметр роута для контекста
     * @return ResponseAlias
     */
    public function handle(Request $request, Closure $next, string $roles, ?string $contextType = null, ?string $contextParam = null): ResponseAlias
    {
        // Пропускаем Prometheus мониторинг без проверки ролей
        $userAgent = $request->userAgent() ?? '';
        if (str_contains($userAgent, 'Prometheus')) {
            return $next($request);
        }
        
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Определяем контекст
        $contextId = $this->resolveContextId($request, $contextType, $contextParam);
        
        // Проверяем роли
        if (!$this->checkRoles($user, $roles, $contextId)) {
            return response()->json([
                'error' => 'Недостаточно прав доступа',
                'required_roles' => $roles,
                'context_type' => $contextType,
                'context_id' => $contextId
            ], 403);
        }

        return $next($request);
    }

    /**
     * Проверить роли пользователя
     */
    protected function checkRoles($user, string $roles, ?int $contextId): bool
    {
        // Разбираем роли по | (OR логика)
        $roleGroups = explode('|', $roles);
        
        foreach ($roleGroups as $roleGroup) {
            // Разбираем роли по , (AND логика)
            $requiredRoles = array_map('trim', explode(',', $roleGroup));
            
            if ($this->hasAllRoles($user, $requiredRoles, $contextId)) {
                return true; // Одна из групп ролей подошла
            }
        }
        
        return false;
    }

    /**
     * Проверить, есть ли у пользователя все роли из списка
     */
    protected function hasAllRoles($user, array $roles, ?int $contextId): bool
    {
        foreach ($roles as $role) {
            if (!$this->authService->hasRole($user, trim($role), $contextId)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Определить ID контекста из запроса
     */
    protected function resolveContextId(Request $request, ?string $contextType, ?string $contextParam): ?int
    {
        if (!$contextType) {
            return null;
        }

        switch ($contextType) {
            case 'system':
                return AuthorizationContext::getSystemContext()->id;
                
            case 'organization':
                $organizationId = $this->extractParam($request, $contextParam ?? 'organization_id');
                if (!$organizationId && $request->user()) {
                    $organizationId = $request->user()->current_organization_id;
                }
                return $organizationId ? AuthorizationContext::getOrganizationContext($organizationId)->id : null;
                
            case 'project':
                $projectId = $this->extractParam($request, $contextParam ?? 'project_id');
                if ($projectId) {
                    // Получаем organization_id проекта
                    $project = \App\Models\Project::find($projectId);
                    $organizationId = $project ? $project->organization_id : null;
                    
                    if ($organizationId) {
                        return AuthorizationContext::getProjectContext($projectId, $organizationId)->id;
                    }
                }
                return null;
                
            default:
                return null;
        }
    }

    /**
     * Извлечь параметр из запроса
     */
    protected function extractParam(Request $request, string $param): mixed
    {
        return $request->route($param) 
            ?? $request->get($param) 
            ?? $request->input($param);
    }
}
