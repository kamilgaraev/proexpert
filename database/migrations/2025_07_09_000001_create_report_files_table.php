<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('report_files', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('path')->unique();
            $table->string('type');
            $table->string('filename');
            $table->string('name')->nullable();
            $table->unsignedBigInteger('size');
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_files');
    }
}; 