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
        Schema::create('import_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('organization_id')->constrained();
            
            // Status: uploading, detected, parsing, processing, completed, failed
            $table->string('status')->index()->default('uploading');
            
            $table->string('file_path')->nullable();
            $table->string('file_name');
            $table->bigInteger('file_size')->default(0);
            $table->string('file_format')->nullable(); // xlsx, xml, rik
            
            // JSON fields for storing context
            $table->jsonb('options')->nullable(); // settings, mapping config
            $table->jsonb('stats')->nullable();   // progress tracking, results summary
            
            $table->text('error_message')->nullable();
            
            $table->timestamps();
            
            // Auto-cleanup index
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_sessions');
    }
};
