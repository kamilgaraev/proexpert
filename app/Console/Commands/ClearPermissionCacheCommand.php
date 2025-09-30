<?php

namespace App\Console\Commands;

use App\Domain\Authorization\Services\PermissionResolver;
use Illuminate\Console\Command;

class ClearPermissionCacheCommand extends Command
{
    protected $signature = 'permissions:clear-cache 
                           {--user= : ID пользователя для очистки кеша разрешений} 
                           {--all : Очистить весь кеш разрешений}';

    protected $description = 'Очистить кеш разрешений пользователей';

    public function handle(PermissionResolver $resolver): int
    {
        if ($this->option('all')) {
            $resolver->clearAllPermissionCache();
            $this->info('Весь кеш разрешений очищен.');
            return 0;
        }

        $userId = $this->option('user');
        if ($userId) {
            $resolver->clearUserPermissionCache((int) $userId);
            $this->info("Кеш разрешений для пользователя {$userId} очищен.");
            return 0;
        }

        $this->error('Необходимо указать --user=ID или --all');
        return 1;
    }
}
