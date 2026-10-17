<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Monitoring\EstimateGenerationPriceLookupIndexRuntime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! $this->guard()) { return; }
        if (DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS available', ['public.estimate_resource_prices'], false)?->available !== true) { return; }
        app(EstimateGenerationPriceLookupIndexRuntime::class)->ensureResourcePrefixLookup();
    }

    public function down(): void
    {
        if ($this->guard()) { app(EstimateGenerationPriceLookupIndexRuntime::class)->dropResourcePrefixLookup(); }
    }

    private function guard(): bool
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql') { return false; }
        if ($connection->getTablePrefix() !== '' || $connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            throw new RuntimeException('Resource price prefix index requires an unprefixed PostgreSQL connection outside a transaction');
        }

        return true;
    }
};
