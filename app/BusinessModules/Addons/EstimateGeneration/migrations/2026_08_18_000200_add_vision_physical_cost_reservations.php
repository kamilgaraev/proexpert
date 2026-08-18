<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_generation_vision_physical_attempts', function (Blueprint $table): void {
            $table->decimal('cost_reservation_amount', 18, 8)->nullable();
            $table->char('cost_reservation_currency', 3)->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE estimate_generation_vision_physical_attempts
                ADD CONSTRAINT estimate_generation_vision_cost_reservation_shape_check
                CHECK (
                    (cost_reservation_amount IS NULL AND cost_reservation_currency IS NULL)
                    OR (cost_reservation_amount IS NOT NULL
                        AND cost_reservation_currency IS NOT NULL
                        AND cost_reservation_amount >= 0
                        AND cost_reservation_currency = 'RUB')
                )
                SQL);
        }
    }

    public function down(): void {}
};
