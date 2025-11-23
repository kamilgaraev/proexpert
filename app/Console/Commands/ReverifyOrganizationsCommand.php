<?php

namespace App\Console\Commands;

use App\Jobs\Organization\VerifyOrganizationJob;
use App\Models\Organization;
use Illuminate\Console\Command;

class ReverifyOrganizationsCommand extends Command
{
    protected $signature = 'organization:reverify-monthly';
    protected $description = 'Re-verify organizations to update their status from external registries';

    public function handle(): void
    {
        $this->info('Starting monthly organization re-verification...');

        // Выбираем организации, которые:
        // 1. Активны
        // 2. Имеют ИНН и Адрес (canBeVerified логика)
        // 3. Уже были верифицированы ранее (чтобы обновить статус) ИЛИ ни разу не проходили верификацию
        
        $query = Organization::query()
            ->where('is_active', true)
            ->whereNotNull('tax_number')
            ->whereNotNull('address');

        $count = $query->count();
        $bar = $this->output->createProgressBar($count);
        
        $query->chunk(100, function ($organizations) use ($bar) {
            foreach ($organizations as $organization) {
                // Отправляем в очередь, чтобы не блокировать выполнение команды и не упереться в лимиты памяти/времени
                VerifyOrganizationJob::dispatch($organization)
                    ->onQueue('default'); // Можно создать отдельную очередь 'verification' если нужно
                
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Dispatched verification jobs for {$count} organizations.");
    }
}

