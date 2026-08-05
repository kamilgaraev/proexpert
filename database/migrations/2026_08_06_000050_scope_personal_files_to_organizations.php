<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('personal_files');

        Schema::create('personal_files', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('storage_key', 1024)->nullable()->unique();
            $table->string('directory', 1024)->default('');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('is_folder')->default(false);
            $table->timestamps();
            $table->index(['organization_id', 'user_id', 'is_folder']);
            $table->index(['organization_id', 'user_id', 'directory']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_files');

        Schema::create('personal_files', function (Blueprint $table): void {
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
};
