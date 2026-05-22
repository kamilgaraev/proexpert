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
        Schema::table('time_entries', function (Blueprint $table): void {
            $table->decimal('hours_worked', 8, 2)->nullable()->change();
            $table->decimal('break_time', 8, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('time_entries')->whereNull('hours_worked')->update(['hours_worked' => 0]);
        DB::table('time_entries')->whereNull('break_time')->update(['break_time' => 0]);

        Schema::table('time_entries', function (Blueprint $table): void {
            $table->decimal('hours_worked', 8, 2)->nullable(false)->change();
            $table->decimal('break_time', 8, 2)->default(0)->nullable(false)->change();
        });
    }
};
