<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('site_request_id')
                ->nullable()
                ->comment('Связь с заявкой с объекта');

            if (Schema::hasTable('site_requests')) {
                $table->foreign('site_request_id')
                    ->references('id')
                    ->on('site_requests')
                    ->nullOnDelete();
            }

            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Исполнитель заявки на закупку');

            $table->string('request_number')->unique()->comment('Номер заявки на закупку');
            $table->string('status', 50)->default('draft')->comment('Статус заявки');
            $table->text('notes')->nullable()->comment('Примечания');
            $table->json('metadata')->nullable()->comment('Дополнительные данные');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index('site_request_id');
            $table->index('assigned_to');
            $table->index('request_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requests');
    }
};
