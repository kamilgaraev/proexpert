<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE warehouse_movements DROP CONSTRAINT IF EXISTS warehouse_movements_movement_type_check');
        DB::statement(<<<'SQL'
ALTER TABLE warehouse_movements
ADD CONSTRAINT warehouse_movements_movement_type_check
CHECK (movement_type IN (
    'receipt',
    'write_off',
    'transfer_in',
    'transfer_out',
    'adjustment',
    'return',
    'reservation',
    'unreservation',
    'reserved_issue'
))
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE warehouse_movements DROP CONSTRAINT IF EXISTS warehouse_movements_movement_type_check');
        DB::statement(<<<'SQL'
ALTER TABLE warehouse_movements
ADD CONSTRAINT warehouse_movements_movement_type_check
CHECK (movement_type IN (
    'receipt',
    'write_off',
    'transfer_in',
    'transfer_out',
    'adjustment',
    'return'
))
SQL);
    }
};
