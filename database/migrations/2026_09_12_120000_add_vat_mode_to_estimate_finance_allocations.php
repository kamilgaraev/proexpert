<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_finance_allocations', function (Blueprint $table): void {
            $table->string('vat_mode', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('estimate_finance_allocations', function (Blueprint $table): void {
            $table->dropColumn('vat_mode');
        });
    }
};
