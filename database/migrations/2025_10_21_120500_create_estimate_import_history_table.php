<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_import_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('estimate_id')->nullable()->constrained()->onDelete('set null');
            
            $table->string('file_name');
            $table->string('file_path');
            $table->bigInteger('file_size')->unsigned();
            $table->string('file_format');
            
            $table->enum('status', ['processing', 'completed', 'failed', 'partial'])->default('processing');
            
            $table->integer('items_total')->default(0);
            $table->integer('items_imported')->default(0);
            $table->integer('items_skipped')->default(0);
            
            $table->json('mapping_data')->nullable();
            $table->json('result_log')->nullable();
            
            $table->integer('processing_time_ms')->nullable();
            
            $table->timestamps();
            
            $table->index(['organization_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_import_history');
    }
};

