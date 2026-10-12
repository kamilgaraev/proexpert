<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('estimates', 'contingency_amount')) {
            (require database_path('migrations/2026_10_11_001100_add_estimate_other_costs_and_resource_precision.php'))->up();
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Estimate money history is forward-only.');
    }
};
