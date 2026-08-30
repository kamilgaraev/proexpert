<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\BusinessModules\Features\BasicWarehouse\Services\ReservationLifecycleService;
use Illuminate\Console\Command;

final class ExpireWarehouseReservations extends Command
{
    protected $signature = 'warehouse:expire-reservations {--limit=200}';

    protected $description = 'Release unused stock held by expired warehouse reservations';

    public function handle(ReservationLifecycleService $reservationLifecycleService): int
    {
        $limit = max(1, min((int) $this->option('limit'), 1000));
        $expiredCount = $reservationLifecycleService->expireDue($limit);

        $this->info("Expired warehouse reservations: {$expiredCount}");

        return self::SUCCESS;
    }
}
