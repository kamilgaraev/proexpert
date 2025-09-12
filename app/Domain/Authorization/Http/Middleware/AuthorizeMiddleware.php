<?php

namespace App\Domain\Authorization\Http\Middleware;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Models\AuthorizationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

/**
 * Middleware для проверки прав доступа
 * 
 * Использование:
 * Route::middleware('authorize:users.view')->get('/users', ...);
 * Route::middleware('authorize:projects.edit,organization')->get('/projects/{id}/edit', ...);
 */
class AuthorizeMiddleware
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
     * @param string $permission Требуемое право
     * @param string|null $contextType Тип контекста (organization, project)
     * @param string|null $contextParam Параметр из роута для определения контекста
     * @return ResponseAlias
     */
    public function handle(Request $request, Closure $next, string $permission, ?string $contextType = null, ?string $contextParam = null): ResponseAlias
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Определяем контекст авторизации
        $context = $this->resolveContext($request, $contextType, $contextParam);
        
        // Проверяем право доступа
        if (!$this->authService->can($user, $permission, $context)) {
            return response()->json([
                'error' => 'Недостаточно прав для выполнения операции',
                'required_permission' => $permission,
                'context' => $context
            ], 403);
        }

        return $next($request);
    }

    /**
     * Определить контекст авторизации из запроса
     */
    protected function resolveContext(Request $request, ?string $contextType, ?string $contextParam): ?array
    {
        $context = [];
        
        // Если contextType не задан, пробуем автоматически определить контекст организации
        if (!$contextType) {
            $organizationId = $this->getOrganizationFromRequest($request);
            if ($organizationId) {
                $context['organization_id'] = $organizationId;
            }
            return empty($context) ? null : $context;
        }
        
        switch ($contextType) {
            case 'organization':
                $organizationId = $this->extractContextId($request, $contextParam ?? 'organization_id', 'organization');
                if ($organizationId) {
                    $context['organization_id'] = $organizationId;
                }
                break;
                
            case 'project':
                $projectId = $this->extractContextId($request, $contextParam ?? 'project_id', 'project');
                if ($projectId) {
                    $context['project_id'] = $projectId;
                    // Также получаем organization_id проекта
                    $organizationId = $this->getProjectOrganizationId($projectId);
                    if ($organizationId) {
                        $context['organization_id'] = $organizationId;
                    }
                }
                break;
        }

        return empty($context) ? null : $context;
    }

    /**
     * Получить ID организации из запроса (установленный middleware SetOrganizationContext)
     */
    protected function getOrganizationFromRequest(Request $request): ?int
    {
        // Пробуем из attributes (установленных SetOrganizationContext)
        $organizationId = $request->attributes->get('current_organization_id');
        if ($organizationId) {
            return (int) $organizationId;
        }
        
        // Пробуем из текущего пользователя
        $user = $request->user();
        if ($user && isset($user->current_organization_id)) {
            return (int) $user->current_organization_id;
        }
        
        return null;
    }

    /**
     * Извлечь ID контекста из параметров запроса
     */
    protected function extractContextId(Request $request, string $param, string $type): ?int
    {
        // Сначала пробуем из route параметров
        $value = $request->route($param);
        
        // Если не нашли, пробуем из query параметров
        if (!$value) {
            $value = $request->get($param);
        }
        
        // Если не нашли, пробуем из тела запроса
        if (!$value) {
            $value = $request->input($param);
        }

        // Для некоторых случаев пробуем альтернативные названия
        if (!$value) {
            $alternativeParams = [
                'organization' => ['org_id', 'organization', 'current_organization_id'],
                'project' => ['project', 'project_id']
            ];
            
            if (isset($alternativeParams[$type])) {
                foreach ($alternativeParams[$type] as $altParam) {
                    $value = $request->route($altParam) ?? $request->get($altParam) ?? $request->input($altParam);
                    if ($value) break;
                }
            }
        }

        // Если все еще не нашли и это organization, берем из текущего пользователя
        if (!$value && $type === 'organization') {
            $user = $request->user();
            if ($user && isset($user->current_organization_id)) {
                $value = $user->current_organization_id;
            }
        }

        return $value ? (int) $value : null;
    }

    /**
     * Получить ID организации для проекта
     */
    protected function getProjectOrganizationId(int $projectId): ?int
    {
        // Здесь нужно получить organization_id проекта из базы данных
        // Это зависит от структуры модели Project
        $project = \App\Models\Project::find($projectId);
        return $project ? $project->organization_id : null;
    }
}
