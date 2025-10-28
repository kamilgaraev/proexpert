<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\OrganizationAccessRestriction;
use App\Models\OrganizationDispute;
use Illuminate\Support\Facades\Log;

class LiftExpiredAccessRestrictions extends Command
{
    protected $signature = 'restrictions:lift-expired {--force : Force lift even if conditions not met}';

    protected $description = 'Автоматически снимает истекшие ограничения доступа для организаций';

    public function handle(): int
    {
        $this->info('Поиск истекших ограничений доступа...');

        $expiredRestrictions = OrganizationAccessRestriction::whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        if ($expiredRestrictions->isEmpty()) {
            $this->info('Нет истекших ограничений.');
            return Command::SUCCESS;
        }

        $this->info("Найдено ограничений: {$expiredRestrictions->count()}");

        $lifted = 0;
        $skipped = 0;

        foreach ($expiredRestrictions as $restriction) {
            if ($this->shouldLiftRestriction($restriction)) {
                $this->liftRestriction($restriction);
                $lifted++;
                
                $this->line("✓ Ограничение #{$restriction->id} снято для организации #{$restriction->organization_id}");
            } else {
                $skipped++;
                $this->line("- Ограничение #{$restriction->id} пропущено (активные расследования)");
            }
        }

        $this->newLine();
        $this->info("Снято ограничений: {$lifted}");
        $this->info("Пропущено: {$skipped}");

        Log::info('[LiftExpiredRestrictions] Command completed', [
            'total' => $expiredRestrictions->count(),
            'lifted' => $lifted,
            'skipped' => $skipped
        ]);

        return Command::SUCCESS;
    }

    private function shouldLiftRestriction(OrganizationAccessRestriction $restriction): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if ($restriction->access_level === 'blocked') {
            $this->warn("Организация #{$restriction->organization_id} заблокирована. Требуется ручная проверка.");
            return false;
        }

        $hasActiveDisputes = OrganizationDispute::where('disputed_organization_id', $restriction->organization_id)
            ->whereIn('status', ['under_investigation', 'pending'])
            ->exists();

        if ($hasActiveDisputes) {
            $this->warn("Организация #{$restriction->organization_id} имеет активные расследования.");
            return false;
        }

        return true;
    }

    private function liftRestriction(OrganizationAccessRestriction $restriction): void
    {
        $restriction->delete();

        Log::info('[LiftExpiredRestrictions] Restriction lifted', [
            'restriction_id' => $restriction->id,
            'organization_id' => $restriction->organization_id,
            'restriction_type' => $restriction->restriction_type,
            'access_level' => $restriction->access_level
        ]);

        Log::channel('security')->info('Access restriction automatically lifted', [
            'organization_id' => $restriction->organization_id,
            'restriction_type' => $restriction->restriction_type,
            'was_active_for' => $restriction->created_at->diffInHours(now()) . ' hours'
        ]);
    }
}

