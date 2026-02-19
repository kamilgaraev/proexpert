<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('file_format', 20)->comment('xlsx, xls, xml, csv');
            $table->string('signature', 64)->index()->comment('md5 хеш от нормализованных заголовков');

            $table->json('original_headers')->comment('Оригинальные заголовки файла');
            $table->json('column_mapping')->comment('Сохранённый маппинг колонок');
            $table->json('section_hints')->nullable()->comment('AI-подсказки по секциям');
            $table->integer('header_row')->nullable();

            $table->unsignedInteger('success_count')->default(1)->comment('Сколько раз успешно применён');
            $table->unsignedInteger('usage_count')->default(1);
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'signature']);
            $table->index(['organization_id', 'file_format', 'success_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_memories');
    }
};
