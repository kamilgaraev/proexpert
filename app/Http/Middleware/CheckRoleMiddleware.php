<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class CheckRoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $roles  Список слагов ролей через запятую (напр., 'owner,admin').
     * @param  string|null $organizationParam Имя параметра маршрута для ID организации.
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, string $roles, ?string $organizationParam = null): Response
    {
        // САМЫЙ ПЕРВЫЙ ЛОГ В МЕТОДЕ
        Log::info('[CheckRoleMiddleware] Entered handle method. VERY START.', [
            'uri' => $request->getRequestUri(),
            'method' => $request->method(),
            'roles_param' => $roles
        ]);

        // Добавляем логирование входного параметра $roles
        Log::debug('[CheckRoleMiddleware] handle method called.', [
            'input_roles_parameter' => $roles,
            'organization_param' => $organizationParam,
            'user_id' => Auth::id() // Логируем ID пользователя на всякий случай
        ]);

        /** @var User|null $user */
        $user = Auth::user();

        if (!$user) {
            // Это не должно произойти, если middleware используется после auth middleware,
            // но лучше подстраховаться.
            throw new AccessDeniedHttpException('User not authenticated.');
        }

        // Получаем массив разрешенных ролей
        $allowedRoles = explode('|', $roles); // Используем | как разделитель
        $allowedRoles = array_map('trim', $allowedRoles); // Убираем пробелы

        if (empty($allowedRoles)) {
             throw new AccessDeniedHttpException('No roles specified for middleware.');
        }

        $organizationId = null;
        $contextSource = 'none';

        // 1. Пытаемся получить из атрибута запроса (установленного SetOrganizationContext)
        /** @var Organization|null $currentOrganization */
        $currentOrganization = $request->attributes->get('current_organization');
        if ($currentOrganization) {
            $organizationId = $currentOrganization->id;
            $contextSource = 'request_attribute';
        }
        // 2. Если атрибута нет И передан параметр маршрута
        elseif ($organizationParam && $request->route($organizationParam)) {
            $organizationId = (int) $request->route($organizationParam);
            $contextSource = 'route_param';
        }
        // 3. Если нет ни атрибута, ни параметра маршрута, используем fallback
        else {
            Log::debug('[CheckRoleMiddleware] Context not found in attribute or route param. Attempting fallback.', ['user_id' => $user->id]);
            $firstOrg = $user->organizations()->first();
            if ($firstOrg) {
                $organizationId = $firstOrg->id;
                $contextSource = 'fallback_first_org';
            } else {
                $organizationId = null;
                $contextSource = 'fallback_failed';
            }
        }

        Log::debug('[CheckRoleMiddleware] Determined Org Context.', [
            'user_id' => $user->id,
            'source' => $contextSource,
            'organization_id' => $organizationId,
            'request_attributes' => $request->attributes->all(),
        ]);

        // Проверяем, требуется ли ID организации хотя бы для одной из ролей
        $requiresOrgContext = false;
        foreach ($allowedRoles as $roleSlug) {
            if ($roleSlug !== \App\Models\Role::ROLE_SYSTEM_ADMIN) {
                $requiresOrgContext = true;
                break;
            }
        }

        // Если контекст организации нужен, но его не удалось определить НИКАК
        if ($requiresOrgContext && !$organizationId) {
             Log::warning('[CheckRoleMiddleware] Failed to determine organization context.', [
                'user_id' => $user->id,
                'required_roles' => $roles,
                'determined_org_id' => $organizationId, // Будет null
                'context_source' => $contextSource,
                'user_has_organizations' => $user->organizations()->exists()
             ]);
             throw new AccessDeniedHttpException('Organization context is required for this role check, but could not be determined.');
        }
        
        // Проверяем, есть ли у пользователя хотя бы одна из разрешенных ролей
        $hasAccess = false;
        foreach ($allowedRoles as $roleSlug) {
            if ($user->hasRole($roleSlug, $organizationId)) {
                $hasAccess = true;
                break;
            }
        }

        if (!$hasAccess) {
             throw new AccessDeniedHttpException(
                sprintf('Access denied. Required roles: %s (Org Context: %s)', $roles, $organizationId ?? 'None')
            );
        }

        // ---- ВРЕМЕННОЕ ДИАГНОСТИЧЕСКОЕ ИЗМЕНЕНИЕ ----
        // Если доступ есть, возвращаем тестовый JSON вместо $next($request)
        Log::info('[CheckRoleMiddleware] Access GRANTED. Bypassing $next($request) for diagnostics.', ['user_id' => $user->id, 'org_id' => $organizationId]);
        return response()->json([
            'success' => true,
            'message' => 'CheckRoleMiddleware passed. User has access.',
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'checked_roles' => $roles
        ]);
        // ---- КОНЕЦ ВРЕМЕННОГО ДИАГНОСТИЧЕСКОГО ИЗМЕНЕНИЯ ----

        // Оригинальный код, который не будет выполнен из-за return выше:
        // Log::info('[CheckRoleMiddleware] Access GRANTED. Calling $next($request).', ['user_id' => $user->id, 'org_id' => $organizationId]);
        // return $next($request);
    }
} 