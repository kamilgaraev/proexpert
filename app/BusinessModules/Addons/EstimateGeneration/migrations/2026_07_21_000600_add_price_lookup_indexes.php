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
        if (DB::getDriverName() === 'pgsql') {
            (new EstimateGenerationPriceLookupIndexRuntime)->ensureAll();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            (new EstimateGenerationPriceLookupIndexRuntime)->dropAll();
        }
    }
};
