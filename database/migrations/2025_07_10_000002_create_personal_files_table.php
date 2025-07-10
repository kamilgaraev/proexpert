<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('personal_files', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('path')->unique();
            $table->string('filename');
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('is_folder')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'is_folder']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_files');
    }
}; 