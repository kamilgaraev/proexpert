<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_participant_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('invited_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invited_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 64);
            $table->string('token', 64)->unique();
            $table->string('status', 32)->default('pending');
            $table->string('organization_name')->nullable();
            $table->string('inn', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone', 32)->nullable();
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['organization_id', 'status']);
            $table->index(['invited_organization_id', 'status']);
            $table->index(['email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_participant_invitations');
    }
};
