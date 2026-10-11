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
        Schema::table('journal_materials', static function (Blueprint $table): void {
            $table->decimal('quantity', 20, 8)->change();
        });
        Schema::table('journal_workers', static function (Blueprint $table): void {
            $table->decimal('hours_worked', 20, 8)->nullable()->change();
        });
        Schema::table('journal_equipment', static function (Blueprint $table): void {
            $table->decimal('hours_used', 20, 8)->nullable()->change();
        });
    }

    public function down(): void
    {
        foreach ([['journal_materials', 'quantity', 3, 1000000000000], ['journal_workers', 'hours_worked', 2, 100000000], ['journal_equipment', 'hours_used', 2, 100000000]] as [$table, $column, $scale, $maximum]) {
            if (DB::table($table)->whereRaw("{$column} <> ROUND({$column}, ?) OR ABS({$column}) >= ?", [$scale, $maximum])->exists()) {
                throw new LogicException('journal_resource_precision_rollback_would_lose_facts');
            }
        }
        Schema::table('journal_materials', static function (Blueprint $table): void {
            $table->decimal('quantity', 15, 3)->change();
        });
        Schema::table('journal_workers', static function (Blueprint $table): void {
            $table->decimal('hours_worked', 10, 2)->nullable()->change();
        });
        Schema::table('journal_equipment', static function (Blueprint $table): void {
            $table->decimal('hours_used', 10, 2)->nullable()->change();
        });
    }
};
