<?php

namespace App\BusinessModules\Core\Users;

use Illuminate\Support\ServiceProvider;

class UsersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Регистрируем сервисы модуля пользователей
    }

    public function boot(): void
    {
        // Модуль использует существующие роуты для пользователей
        // Дополнительная логика загрузки, если необходимо
    }
}
