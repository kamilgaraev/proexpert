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
        Schema::create('project_addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->unique();
            
            // Исходный адрес
            $table->text('raw_address');
            
            // Структурированные компоненты
            $table->string('country', 100)->nullable();
            $table->string('region', 200)->nullable();
            $table->string('city', 200)->nullable();
            $table->string('district', 200)->nullable();
            $table->string('street', 300)->nullable();
            $table->string('house', 50)->nullable();
            $table->string('postal_code', 20)->nullable();
            
            // Координаты
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            
            // Метаданные геокодирования
            $table->timestamp('geocoded_at')->nullable();
            $table->string('geocoding_provider', 50)->nullable()->comment('dadata, yandex, nominatim');
            $table->decimal('geocoding_confidence', 3, 2)->nullable()->comment('0.0 - 1.0');
            $table->text('geocoding_error')->nullable();
            
            $table->timestamps();
            
            // Foreign key
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            
            // Индексы
            $table->index(['latitude', 'longitude'], 'idx_project_addresses_location');
            $table->index('geocoded_at', 'idx_project_addresses_geocoded');
            $table->index('geocoding_provider', 'idx_project_addresses_provider');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_addresses');
    }
};

