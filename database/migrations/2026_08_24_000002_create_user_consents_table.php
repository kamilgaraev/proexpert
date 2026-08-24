<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->string('version', 64);
            $table->timestampTz('accepted_at');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'type', 'version'], 'user_consents_user_type_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_consents');
    }
};
