<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_finance_allocations', function (Blueprint $table): void {
            $table->jsonb('accepted_basis')->nullable();
            $table->jsonb('condition_basis')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('estimate_finance_allocations', function (Blueprint $table): void {
            $table->dropColumn(['accepted_basis', 'condition_basis']);
        });
    }
};
