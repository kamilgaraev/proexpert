<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_briefings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conducted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('briefing_number', 80)->unique();
            $table->string('title');
            $table->string('briefing_type', 80);
            $table->string('location_name')->nullable();
            $table->dateTime('conducted_at');
            $table->jsonb('topics')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'project_id', 'conducted_at']);
        });

        Schema::create('safety_briefing_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('briefing_id')->constrained('safety_briefings')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('external_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('role_name')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['briefing_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_briefing_participants');
        Schema::dropIfExists('safety_briefings');
    }
};
