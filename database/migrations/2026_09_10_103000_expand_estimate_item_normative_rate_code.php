<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_items', function (Blueprint $table): void {
            $table->string('normative_rate_code', 1000)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table): void {
            $table->string('normative_rate_code', 100)->nullable()->change();
        });
    }
};
