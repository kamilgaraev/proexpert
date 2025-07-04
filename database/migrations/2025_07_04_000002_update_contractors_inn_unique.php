<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            // Снимаем уникальность с одного поля inn, если она была
            $table->dropUnique('contractors_inn_unique');

            // Для ИНН допускаем NULL, но если не NULL, то уникален в пределах организации
            $table->unique(['organization_id', 'inn'], 'contractors_org_inn_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            $table->dropUnique('contractors_org_inn_unique');
            $table->unique('inn');
        });
    }
}; 