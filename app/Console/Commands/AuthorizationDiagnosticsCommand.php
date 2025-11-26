<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Domain\Authorization\Services\RoleScanner;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;

/**
 * Команда для диагностики проблем авторизации
 */
class AuthorizationDiagnosticsCommand extends Command
{
    protected $signature = 'auth:diagnose 
                            {--user= : ID или email пользователя для проверки}
                            {--clear-cache : Очистить кеш ролей}
                            {--check-interface= : Проверить доступ к интерфейсу (lk, admin, mobile)}
                            {--org= : ID организации для контекста}';

    protected $description = 'Диагностика проблем авторизации и доступа к интерфейсам';

    public function __construct(
        private readonly RoleScanner $roleScanner,
        private readonly AuthorizationService $authService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('clear-cache')) {
            $this->clearRoleCache();
        }

        $userIdentifier = $this->option('user');
        if ($userIdentifier) {
            $this->diagnoseUser($userIdentifier);
        }

        if (!$userIdentifier && !$this->option('clear-cache')) {
            $this->showRolesStats();
        }

        return Command::SUCCESS;
    }

    /**
     * Очистить кеш ролей
     */
    private function clearRoleCache(): void
    {
        $this->info('Очищаю кеш ролей...');
        
        $this->roleScanner->clearCache();
        
        // Очищаем также кеш разрешений в array driver
        \Cache::flush();
        
        $this->info('✅ Кеш ролей очищен');
    }

    /**
     * Показать статистику ролей
     */
    private function showRolesStats(): void
    {
        $this->info('=== Статистика ролей ===');
        
        $stats = $this->roleScanner->getStats();
        
        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Всего ролей', $stats['total']],
                ['По контексту: system', $stats['by_context']['system'] ?? 0],
                ['По контексту: organization', $stats['by_context']['organization'] ?? 0],
                ['По контексту: project', $stats['by_context']['project'] ?? 0],
                ['Интерфейс: lk', $stats['by_interface']['lk'] ?? 0],
                ['Интерфейс: admin', $stats['by_interface']['admin'] ?? 0],
                ['Интерфейс: mobile', $stats['by_interface']['mobile'] ?? 0],
            ]
        );

        // Показать все роли с interface_access
        $this->info("\n=== Роли с доступом к интерфейсам ===");
        $roles = $this->roleScanner->getAllRoles();
        
        $tableData = [];
        foreach ($roles as $role) {
            $interfaces = $role['interface_access'] ?? [];
            $tableData[] = [
                $role['slug'],
                $role['name'],
                $role['context'],
                implode(', ', $interfaces),
            ];
        }
        
        $this->table(['Slug', 'Название', 'Контекст', 'Интерфейсы'], $tableData);
    }

    /**
     * Диагностика конкретного пользователя
     */
    private function diagnoseUser(string $identifier): void
    {
        $user = is_numeric($identifier) 
            ? User::find($identifier) 
            : User::where('email', $identifier)->first();

        if (!$user) {
            $this->error("Пользователь не найден: {$identifier}");
            return;
        }

        $this->info("=== Диагностика пользователя ===");
        $this->table(['Поле', 'Значение'], [
            ['ID', $user->id],
            ['Email', $user->email],
            ['Имя', $user->name],
            ['Текущая организация', $user->current_organization_id ?? 'не установлена'],
        ]);

        // Получаем все назначенные роли
        $this->info("\n=== Назначенные роли (user_role_assignments) ===");
        $assignments = UserRoleAssignment::where('user_id', $user->id)->with('context')->get();
        
        if ($assignments->isEmpty()) {
            $this->warn("⚠️ У пользователя НЕТ назначенных ролей в таблице user_role_assignments!");
            $this->warn("Это может быть причиной проблемы с доступом к интерфейсам.");
            return;
        }

        $assignmentData = [];
        foreach ($assignments as $assignment) {
            $role = $this->roleScanner->getRole($assignment->role_slug);
            $interfaceAccess = $role['interface_access'] ?? [];
            
            $assignmentData[] = [
                $assignment->id,
                $assignment->role_slug,
                $assignment->role_type,
                $assignment->context?->type ?? 'unknown',
                $assignment->context?->resource_id,
                $assignment->is_active ? '✅' : '❌',
                $assignment->expires_at?->format('Y-m-d') ?? '-',
                implode(', ', $interfaceAccess),
            ];
        }
        
        $this->table(
            ['ID', 'Role Slug', 'Type', 'Context', 'Resource ID', 'Active', 'Expires', 'Interface Access'],
            $assignmentData
        );

        // Проверяем доступ к интерфейсу
        $interfaceToCheck = $this->option('check-interface');
        if ($interfaceToCheck) {
            $this->checkInterfaceAccess($user, $interfaceToCheck);
        } else {
            // Проверяем все интерфейсы
            foreach (['lk', 'admin', 'mobile'] as $interface) {
                $this->checkInterfaceAccess($user, $interface);
            }
        }
    }

    /**
     * Проверить доступ к интерфейсу
     */
    private function checkInterfaceAccess(User $user, string $interface): void
    {
        $orgId = $this->option('org') ?? $user->current_organization_id;
        
        $context = $orgId 
            ? AuthorizationContext::getOrganizationContext((int)$orgId)
            : null;
        
        $hasAccess = $this->authService->canAccessInterface($user, $interface, $context);
        
        $contextInfo = $context 
            ? "контекст: org_{$context->resource_id}" 
            : "без контекста";
        
        if ($hasAccess) {
            $this->info("✅ Доступ к интерфейсу '{$interface}' ({$contextInfo}): РАЗРЕШЕН");
        } else {
            $this->error("❌ Доступ к интерфейсу '{$interface}' ({$contextInfo}): ЗАПРЕЩЕН");
            
            // Выводим диагностику
            $roles = $this->authService->getUserRoles($user, $context);
            
            if ($roles->isEmpty()) {
                $this->warn("   Причина: Нет активных ролей в контексте");
            } else {
                $this->warn("   Роли пользователя в контексте:");
                foreach ($roles as $role) {
                    $roleData = $this->roleScanner->getRole($role->role_slug);
                    $interfaces = $roleData['interface_access'] ?? [];
                    $this->warn("   - {$role->role_slug}: interfaces = [" . implode(', ', $interfaces) . "]");
                }
            }
        }
    }
}

