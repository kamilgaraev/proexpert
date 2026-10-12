<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Estimate decimal precision requires PostgreSQL.');
        }
        $columns = ['quantity' => [24, 8], 'quantity_coefficient' => [24, 8], 'quantity_total' => [24, 8],
            'actual_quantity' => [24, 8], 'unit_price' => [24, 4], 'base_unit_price' => [24, 4],
            'current_unit_price' => [24, 4], 'actual_unit_price' => [24, 4]];
        $current = DB::table('information_schema.columns')->whereRaw('table_schema = current_schema()')
            ->where('table_name', 'estimate_items')->whereIn('column_name', array_keys($columns))->get()->keyBy('column_name');
        $changes = [];
        foreach ($columns as $column => [$precision, $scale]) {
            $definition = $current[$column] ?? null;
            if ($definition === null) {
                throw new RuntimeException('Estimate decimal column is missing: '.$column);
            }
            if ((int) $definition->numeric_precision < $precision || (int) $definition->numeric_scale < $scale) {
                $changes[] = 'ALTER COLUMN '.$column.' TYPE numeric('.$precision.','.$scale.')';
            }
        }
        if ($changes !== []) {
            DB::statement('ALTER TABLE estimate_items '.implode(', ', $changes));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Accepted estimate precision is forward-only; narrowing would destroy source money.');
    }
};
