<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->string('equipment_type')->nullable()->after('worker_count')->comment('Тип техники: Кран, Экскаватор и т.д.');
            $table->string('equipment_number')->nullable()->after('equipment_type')->comment('Гос. номер или инвентарный номер техники');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropColumn(['equipment_type', 'equipment_number']);
        });
    }
};
