<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_stats', static function (Blueprint $table): void {
            $table->decimal('cost_rub', 14, 6)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_stats', static function (Blueprint $table): void {
            $table->decimal('cost_rub', 10, 2)->default(0)->change();
        });
    }
};
