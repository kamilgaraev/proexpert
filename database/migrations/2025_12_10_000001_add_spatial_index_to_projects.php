<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Добавляем поля для геокодирования если их нет
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'geocoded_at')) {
                $table->timestamp('geocoded_at')->nullable()->after('updated_at');
            }
            if (!Schema::hasColumn('projects', 'geocoding_status')) {
                $table->string('geocoding_status', 20)->default('pending')->after('geocoded_at');
            }
        });

        // Создаем spatial index для быстрого поиска по координатам
        DB::statement('CREATE INDEX IF NOT EXISTS idx_projects_location ON projects(latitude, longitude) WHERE latitude IS NOT NULL AND longitude IS NOT NULL');
        
        // Индекс для статуса геокодирования
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasIndex('projects', 'idx_projects_geocoding_status')) {
                $table->index('geocoding_status', 'idx_projects_geocoding_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_projects_location');
        
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('idx_projects_geocoding_status');
            $table->dropColumn(['geocoded_at', 'geocoding_status']);
        });
    }
};

