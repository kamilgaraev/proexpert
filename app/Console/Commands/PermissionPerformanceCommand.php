<?php

namespace App\Console\Commands;

use App\Domain\Authorization\Services\PermissionResolver;
use App\Domain\Authorization\Models\UserRoleAssignment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PermissionPerformanceCommand extends Command
{
    protected $signature = 'permissions:performance 
                           {--test-user=13 : ID пользователя для тестирования} 
                           {--iterations=10 : Количество итераций теста}';

    protected $description = 'Тестировать производительность системы разрешений';

    public function handle(PermissionResolver $resolver): int
    {
        $userId = (int) $this->option('test-user');
        $iterations = (int) $this->option('iterations');

        $this->info("Тестирование производительности разрешений для пользователя {$userId}");
        $this->info("Количество итераций: {$iterations}");

        // Получаем назначения ролей пользователя
        $assignments = UserRoleAssignment::where('user_id', $userId)
            ->where('is_active', true)
            ->with('context')
            ->get();

        if ($assignments->isEmpty()) {
            $this->error("У пользователя {$userId} нет активных ролей");
            return 1;
        }

        $testPermissions = [
            'users.view',
            'users.manage', 
            'roles.view_custom',
            'roles.create_custom',
            'projects.view',
            'projects.create'
        ];

        $this->info('Очищаем кеш для чистого теста...');
        $resolver->clearUserPermissionCache($userId);

        foreach ($assignments as $assignment) {
            $this->line("Тестирование роли: {$assignment->role_slug}");
            
            foreach ($testPermissions as $permission) {
                $times = [];
                
                // Тест без кеша
                $resolver->clearUserPermissionCache($userId);
                $start = microtime(true);
                $result = $resolver->hasPermission($assignment, $permission, ['organization_id' => 6]);
                $timeWithoutCache = (microtime(true) - $start) * 1000;
                
                // Тест с кешем
                for ($i = 0; $i < $iterations; $i++) {
                    $start = microtime(true);
                    $resolver->hasPermission($assignment, $permission, ['organization_id' => 6]);
                    $times[] = (microtime(true) - $start) * 1000;
                }
                
                $avgTime = array_sum($times) / count($times);
                $status = $result ? '✓' : '✗';
                
                $this->line(sprintf(
                    "  %s %-20s | Без кеша: %6.2f мс | С кешем: %6.2f мс | Разрешено: %s",
                    $status,
                    $permission,
                    $timeWithoutCache,
                    $avgTime,
                    $result ? 'Да' : 'Нет'
                ));
            }
            $this->line('');
        }

        // Статистика кеша
        $cacheStats = $this->getCacheStats($userId);
        $this->info('Статистика кеша:');
        $this->line("  Записей в кеше: {$cacheStats['entries']}");
        $this->line("  Общий размер ключей: {$cacheStats['size']} байт");

        return 0;
    }

    private function getCacheStats(int $userId): array
    {
        $entries = 0;
        $size = 0;
        
        // Простая оценка статистики кеша
        for ($i = 0; $i < 100; $i++) {
            $key = "permission_{$userId}_test_role_test_permission_{$i}_" . md5('test');
            if (Cache::has($key)) {
                $entries++;
                $size += strlen($key);
            }
        }
        
        return ['entries' => $entries, 'size' => $size];
    }
}
