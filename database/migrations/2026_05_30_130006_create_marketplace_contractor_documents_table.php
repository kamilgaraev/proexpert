<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_contractor_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('marketplace_contractor_profiles')->cascadeOnDelete();
            $table->string('type', 80);
            $table->string('title');
            $table->string('file_path');
            $table->string('status', 40)->default('pending');
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['profile_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_contractor_documents');
    }
};
