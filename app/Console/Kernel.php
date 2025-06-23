<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
// Log::channel('stderr') больше не используется здесь, если billing:process-renewals не использует Log
// Если использует, то импорт Log нужно оставить.
// Пока предполагаем, что не использует и удаляем (если нет - вернем).
// use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * These schedules are used to run the console commands.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();

        // Запускать команду обработки продлений подписок ежедневно в определенное время
        // (например, в 2 часа ночи серверного времени)
        $schedule->command('billing:process-renewals')->dailyAt('02:00');

        // Ежедневная очистка просроченных отчетов актов (в 3 часа ночи)
        $schedule->command('act-reports:cleanup')->dailyAt('03:00');

        // Ежедневная очистка "осиротевших" файлов была перенесена в routes/console.php

        // Для более частого тестирования можно использовать, например, ->everyMinute()
        // $schedule->command('billing:process-renewals')->everyMinute(); 
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
} 