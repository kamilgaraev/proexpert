<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', static function (Blueprint $table): void {
            $table->string('budget_override_reason', 1000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', static function (Blueprint $table): void {
            $table->dropColumn('budget_override_reason');
        });
    }
};
