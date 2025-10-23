<?php

namespace App\Console\Commands\Billing;

use Illuminate\Console\Command;
use App\Models\OrganizationSubscription;
use App\Jobs\RenewSubscriptionsJob;
use Illuminate\Support\Facades\DB;

class RenewSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:renew 
                            {--organization= : ID организации для продления конкретной подписки}
                            {--dry-run : Показать подписки которые будут продлены без реального продления}
                            {--days-ahead=1 : За сколько дней до окончания начинать продление}';

    protected $description = 'Автоматическое продление подписок организаций с включенным автоплатежом';

    public function handle(): int
    {
        $daysAhead = (int) $this->option('days-ahead');
        $organizationId = $this->option('organization');
        $isDryRun = $this->option('dry-run');
        
        $this->info("Поиск подписок для автопродления...");
        
        $query = OrganizationSubscription::with(['organization', 'plan'])
            ->where('is_auto_payment_enabled', true)
            ->where('status', 'active')
            ->whereNull('canceled_at')
            ->where('ends_at', '<=', now()->addDays($daysAhead))
            ->where('ends_at', '>', now()->subDay());
            
        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }
        
        $subscriptions = $query->get();
        
        if ($subscriptions->isEmpty()) {
            $this->info('Нет подписок для продления.');
            return Command::SUCCESS;
        }
        
        $this->info("Найдено подписок для продления: " . $subscriptions->count());
        $this->newLine();
        
        if ($isDryRun) {
            $this->warn('Режим DRY RUN - подписки не будут продлены');
            $this->newLine();
        }
        
        $table = [];
        $successCount = 0;
        $failedCount = 0;
        $skippedCount = 0;
        
        foreach ($subscriptions as $subscription) {
            $organizationName = $subscription->organization->name ?? 'N/A';
            $planName = $subscription->plan->name ?? 'N/A';
            $endsAt = $subscription->ends_at->format('Y-m-d H:i');
            $daysLeft = now()->diffInDays($subscription->ends_at, false);
            
            $table[] = [
                'ID' => $subscription->id,
                'Организация' => $organizationName,
                'План' => $planName,
                'Заканчивается' => $endsAt,
                'Осталось дней' => round($daysLeft, 1),
                'Цена' => $subscription->plan->price . ' руб.',
            ];
            
            if (!$isDryRun) {
                try {
                    RenewSubscriptionsJob::dispatch($subscription->organization_id);
                    $successCount++;
                    $this->info("✓ Задача на продление поставлена в очередь: {$organizationName}");
                } catch (\Exception $e) {
                    $failedCount++;
                    $this->error("✗ Ошибка постановки в очередь {$organizationName}: " . $e->getMessage());
                }
            } else {
                $skippedCount++;
            }
        }
        
        $this->newLine();
        $this->table(
            ['ID', 'Организация', 'План', 'Заканчивается', 'Осталось дней', 'Цена'],
            $table
        );
        
        $this->newLine();
        
        if ($isDryRun) {
            $this->info("Всего найдено для продления: {$skippedCount}");
        } else {
            $this->info("Успешно поставлено в очередь: {$successCount}");
            if ($failedCount > 0) {
                $this->error("Ошибок: {$failedCount}");
            }
        }
        
        return Command::SUCCESS;
    }
}

