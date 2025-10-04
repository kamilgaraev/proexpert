<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\OrganizationModuleActivation;
use App\Modules\Core\AccessController;
use App\Modules\Events\TrialExpired;

class ConvertExpiredTrials extends Command
{
    protected $signature = 'modules:convert-expired-trials';
    protected $description = 'Проверяет истекшие trial периоды и деактивирует модули';

    public function handle(AccessController $accessController)
    {
        $this->info('Проверка истекших trial периодов...');
        
        $expiredTrials = OrganizationModuleActivation::where('status', 'trial')
            ->where('trial_ends_at', '<', now())
            ->with(['organization', 'module'])
            ->get();
        
        if ($expiredTrials->isEmpty()) {
            $this->info('Истекших trial периодов не найдено');
            return Command::SUCCESS;
        }
        
        $this->info("Найдено {$expiredTrials->count()} истекших trial периодов");
        
        $bar = $this->output->createProgressBar($expiredTrials->count());
        $bar->start();
        
        foreach ($expiredTrials as $activation) {
            try {
                $activation->update([
                    'status' => 'expired',
                    'expires_at' => now()
                ]);
                
                $accessController->clearAccessCache($activation->organization_id);
                
                event(new TrialExpired($activation));
                
                $this->newLine();
                $this->info(
                    "✓ Trial истек: организация {$activation->organization->name}, " .
                    "модуль {$activation->module->name}"
                );
                
                // TODO: Отправить уведомление организации о истечении trial
                // (будет реализовано после создания системы уведомлений)
                
            } catch (\Exception $e) {
                $this->newLine();
                $this->error(
                    "✗ Ошибка для организации {$activation->organization_id}: " . 
                    $e->getMessage()
                );
            }
            
            $bar->advance();
        }
        
        $bar->finish();
        $this->newLine(2);
        $this->info('Обработка завершена');
        
        return Command::SUCCESS;
    }
}

