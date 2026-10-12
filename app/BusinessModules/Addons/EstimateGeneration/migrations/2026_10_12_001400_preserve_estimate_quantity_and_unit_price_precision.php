<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (require database_path('migrations/2026_10_12_001400_preserve_estimate_quantity_and_unit_price_precision.php'))->up();
    }

    public function down(): void
    {
        throw new RuntimeException('Accepted estimate precision is forward-only.');
    }
};
