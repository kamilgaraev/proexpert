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
        Schema::create('cost_categories', function (Blueprint $table) {
            $table->id();
            
            // Основная информация
            $table->string('name')->comment('Наименование категории затрат');
            $table->string('code')->nullable()->comment('Внутренний код категории');
            $table->string('external_code')->nullable()->comment('Код в СБИС/1C');
            $table->text('description')->nullable()->comment('Описание категории');
            
            // Организационная структура
            $table->unsignedBigInteger('organization_id')->comment('ID организации');
            $table->unsignedBigInteger('parent_id')->nullable()->comment('ID родительской категории');
            
            // Дополнительные атрибуты
            $table->boolean('is_active')->default(true)->comment('Активна/неактивна');
            $table->integer('sort_order')->default(0)->comment('Порядок сортировки');
            $table->json('additional_attributes')->nullable()->comment('Дополнительные атрибуты в JSON');
            
            // Метаданные
            $table->timestamps();
            $table->softDeletes();
            
            // Индексы
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('parent_id')->references('id')->on('cost_categories')->onDelete('set null');
            $table->index(['organization_id', 'is_active']);
            $table->index('external_code');
            
            // Уникальность кода внутри организации
            $table->unique(['code', 'organization_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cost_categories');
    }
};
