<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_performance_acts', function (Blueprint $table): void {
            $table->char('currency', 3)->nullable();
        });
        Schema::table('performance_act_lines', function (Blueprint $table): void {
            $table->char('currency', 3)->nullable();
        });
        Schema::table('performance_act_completed_works', function (Blueprint $table): void {
            $table->char('currency', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('performance_act_completed_works', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });
        Schema::table('performance_act_lines', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });
        Schema::table('contract_performance_acts', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });
    }
};
