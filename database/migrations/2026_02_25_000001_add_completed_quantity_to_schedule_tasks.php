<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_tasks', function (Blueprint $table) {
            $table->decimal('completed_quantity', 12, 4)
                ->nullable()
                ->after('quantity')
                ->comment('Фактически выполненный объём работ');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_tasks', function (Blueprint $table) {
            $table->dropColumn('completed_quantity');
        });
    }
};
