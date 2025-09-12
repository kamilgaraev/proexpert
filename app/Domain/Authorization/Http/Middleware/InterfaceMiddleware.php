<?php

namespace App\Domain\Authorization\Http\Middleware;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Models\AuthorizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

/**
 * Middleware для проверки доступа к интерфейсам
 * 
 * Использование:
 * Route::middleware('interface:lk')->group(function () { ... });
 * Route::middleware('interface:admin,organization')->get('/admin', ...);
 * Route::middleware('interface:mobile,project,project_id')->get('/mobile', ...);
 */
class InterfaceMiddleware
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
     * @param string $interface Интерфейс (lk, admin, mobile)
     * @param string|null $contextType Тип контекста
     * @param string|null $contextParam Параметр роута для контекста
     * @return ResponseAlias
     */
    public function handle(Request $request, Closure $next, string $interface, ?string $contextType = null, ?string $contextParam = null): ResponseAlias
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Определяем контекст
        $context = $this->resolveContext($request, $contextType, $contextParam);
        
        // Проверяем доступ к интерфейсу
        if (!$this->authService->canAccessInterface($user, $interface, $context)) {
            return response()->json([
                'error' => 'Доступ к интерфейсу запрещен',
                'interface' => $interface,
                'message' => $this->getInterfaceMessage($interface)
            ], 403);
        }

        // Добавляем информацию об интерфейсе в запрос для дальнейшего использования
        $request->merge(['current_interface' => $interface]);

        return $next($request);
    }

    /**
     * Определить контекст авторизации
     */
    protected function resolveContext(Request $request, ?string $contextType, ?string $contextParam): ?AuthorizationContext
    {
        if (!$contextType) {
            return null;
        }

        switch ($contextType) {
            case 'system':
                return AuthorizationContext::getSystemContext();
                
            case 'organization':
                $organizationId = $this->extractParam($request, $contextParam ?? 'organization_id');
                if (!$organizationId && $request->user()) {
                    $organizationId = $request->user()->current_organization_id;
                }
                return $organizationId ? AuthorizationContext::getOrganizationContext($organizationId) : null;
                
            case 'project':
                $projectId = $this->extractParam($request, $contextParam ?? 'project_id');
                if ($projectId) {
                    $project = \App\Models\Project::find($projectId);
                    $organizationId = $project ? $project->organization_id : null;
                    
                    if ($organizationId) {
                        return AuthorizationContext::getProjectContext($projectId, $organizationId);
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

    /**
     * Получить сообщение об ограничении доступа к интерфейсу
     */
    protected function getInterfaceMessage(string $interface): string
    {
        $messages = [
            'lk' => 'Доступ в личный кабинет разрешен только владельцам и администраторам организаций',
            'admin' => 'Доступ в административную панель разрешен только системным администраторам и модераторам',
            'mobile' => 'Доступ к мобильному приложению разрешен только работникам на объектах',
        ];

        return $messages[$interface] ?? 'Доступ к данному интерфейсу ограничен';
    }
}
