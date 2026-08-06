<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE production_acceptance_events
    ALTER COLUMN accepted_quantity_delta TYPE NUMERIC(20, 4),
    ALTER COLUMN planned_quantity TYPE NUMERIC(20, 4),
    ALTER COLUMN reported_quantity TYPE NUMERIC(20, 4)
SQL);
    }

    public function down(): void
    {
        $hasFourDecimalValues = DB::table('production_acceptance_events')
            ->whereRaw('accepted_quantity_delta <> ROUND(accepted_quantity_delta, 3)')
            ->orWhereRaw('planned_quantity <> ROUND(planned_quantity, 3)')
            ->orWhereRaw('reported_quantity <> ROUND(reported_quantity, 3)')
            ->exists();

        if ($hasFourDecimalValues) {
            throw new LogicException('production_acceptance_quantity_precision_rollback_unsafe');
        }

        DB::statement(<<<'SQL'
ALTER TABLE production_acceptance_events
    ALTER COLUMN accepted_quantity_delta TYPE NUMERIC(20, 3),
    ALTER COLUMN planned_quantity TYPE NUMERIC(20, 3),
    ALTER COLUMN reported_quantity TYPE NUMERIC(20, 3)
SQL);
    }
};
