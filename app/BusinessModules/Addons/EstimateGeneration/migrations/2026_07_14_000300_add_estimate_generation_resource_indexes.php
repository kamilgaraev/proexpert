<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Monitoring\EstimateGenerationResourceIndexRuntime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        (new EstimateGenerationResourceIndexRuntime)->ensureAll();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        (new EstimateGenerationResourceIndexRuntime)->dropAll();
    }
};
