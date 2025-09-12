<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations - создание таблицы контекстов авторизации.
     */
    public function up(): void
    {
        Schema::create('authorization_contexts', function (Blueprint $table) {
            $table->id();
            
            // Тип контекста: system, organization, project
            $table->enum('type', ['system', 'organization', 'project'])->index();
            
            // ID ресурса (organization_id или project_id), NULL для системного контекста
            $table->unsignedBigInteger('resource_id')->nullable()->index();
            
            // Родительский контекст (для иерархии)
            $table->unsignedBigInteger('parent_context_id')->nullable();
            $table->foreign('parent_context_id')->references('id')->on('authorization_contexts')->onDelete('set null');
            
            // Метаданные контекста (JSON)
            $table->json('metadata')->nullable();
            
            // Составной индекс для быстрого поиска контекста
            $table->index(['type', 'resource_id']);
            $table->index(['parent_context_id']);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('authorization_contexts');
    }
};
