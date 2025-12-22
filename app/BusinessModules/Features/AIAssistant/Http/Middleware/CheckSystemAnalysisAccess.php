<?php

namespace App\BusinessModules\Features\AIAssistant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSystemAnalysisAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        // Проверяем, что модуль системного анализа включен
        if (!config('ai-assistant.system_analysis.enabled', true)) {
            return response()->json([
                'success' => false,
                'message' => 'Модуль системного анализа отключен',
            ], 403);
        }

        // Проверяем, что модуль AIAssistant активирован для организации
        $organizationId = $request->organization_id ?? $user->organization_id;
        
        // Здесь можно добавить проверку активации модуля через OrganizationModule
        // if (!$this->isModuleActive($organizationId)) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Модуль AI Assistant не активирован для вашей организации',
        //     ], 403);
        // }

        // Проверяем права доступа (только администраторы)
        // В зависимости от вашей системы прав, адаптируйте эту проверку
        if (!$this->isAdmin($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Доступ запрещен. Только администраторы могут использовать системный анализ.',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Проверить, является ли пользователь администратором
     */
    private function isAdmin($user): bool
    {
        // Проверяем роль пользователя
        // Адаптируйте под вашу систему ролей
        return $user->hasRole('admin') 
            || $user->hasRole('super_admin') 
            || $user->hasRole('organization_admin')
            || $user->role === 'admin';
    }

    /**
     * Проверить, активирован ли модуль для организации
     */
    private function isModuleActive(int $organizationId): bool
    {
        // Проверка через OrganizationModule
        // return \App\Models\OrganizationModule::where('organization_id', $organizationId)
        //     ->where('module_slug', 'ai-assistant')
        //     ->where('is_active', true)
        //     ->exists();
        
        // Временно возвращаем true
        return true;
    }
}

