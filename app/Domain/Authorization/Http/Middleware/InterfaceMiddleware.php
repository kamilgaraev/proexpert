<?php

namespace App\Domain\Authorization\Http\Middleware;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
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
     * @param  string  $interface  Интерфейс (lk, admin, mobile)
     * @param  string|null  $contextType  Тип контекста
     * @param  string|null  $contextParam  Параметр роута для контекста
     */
    public function handle(Request $request, Closure $next, string $interface, ?string $contextType = null, ?string $contextParam = null): ResponseAlias
    {
        $user = $request->user();

        if (! $user) {
            return \App\Http\Responses\AdminResponse::fromPayload(['error' => trans_message('errors.unauthenticated')], 401);
        }

        // Определяем контекст
        $context = $this->resolveContext($request, $contextType, $contextParam);

        // Проверяем доступ к интерфейсу
        $hasAccess = $this->authService->canAccessInterface($user, $interface, $context);

        if (! $hasAccess) {
            // Диагностика для отладки проблем с доступом к интерфейсам
            \Log::warning('interface.access.denied', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'interface' => $interface,
                'context_type' => $contextType,
                'context_id' => $context?->id,
                'context_resource_id' => $context?->resource_id,
                'current_organization_id' => $request->attributes->get('current_organization_id'),
                'user_current_org' => $user->current_organization_id,
                'uri' => $request->getRequestUri(),
            ]);

            return \App\Http\Responses\AdminResponse::fromPayload([
                'error' => trans_message('errors.interface_access.denied'),
                'interface' => $interface,
                'message' => $this->getInterfaceMessage($interface),
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
        // Если contextType не указан явно, пробуем автоматически определить контекст
        // из ранее установленных атрибутов (middleware organization.context)
        if (! $contextType) {
            // Пробуем получить organization_id из атрибутов запроса
            $organizationId = $request->attributes->get('current_organization_id');
            if ($organizationId) {
                return AuthorizationContext::getOrganizationContext($organizationId);
            }

            // Fallback на current_organization_id пользователя
            $user = $request->user();
            if ($user && $user->current_organization_id) {
                return AuthorizationContext::getOrganizationContext($user->current_organization_id);
            }

            return null;
        }

        switch ($contextType) {
            case 'system':
                return AuthorizationContext::getSystemContext();

            case 'organization':
                $organizationId = $this->extractParam($request, $contextParam ?? 'organization_id');
                if (! $organizationId) {
                    // Пробуем получить из атрибутов запроса
                    $organizationId = $request->attributes->get('current_organization_id');
                }
                if (! $organizationId && $request->user()) {
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
        return match ($interface) {
            'lk' => trans_message('errors.interface_access.lk'),
            'admin' => trans_message('errors.interface_access.admin'),
            'mobile' => trans_message('errors.interface_access.mobile'),
            default => trans_message('errors.interface_access.default'),
        };
    }
}
