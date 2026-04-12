<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brigades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('team_size')->default(1);
            $table->string('contact_person');
            $table->string('contact_phone');
            $table->string('contact_email');
            $table->string('availability_status')->default('available');
            $table->string('verification_status')->default('draft');
            $table->json('regions')->nullable();
            $table->decimal('rating', 4, 2)->default(0);
            $table->unsignedInteger('completed_projects_count')->default(0);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('brigade_specializations', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('brigade_profile_specialization', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brigade_id')->constrained('brigades')->cascadeOnDelete();
            $table->foreignId('specialization_id')->constrained('brigade_specializations')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['brigade_id', 'specialization_id'], 'brigade_profile_specialization_unique');
        });

        Schema::create('brigade_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brigade_id')->constrained('brigades')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('full_name');
            $table->string('role');
            $table->string('phone')->nullable();
            $table->boolean('is_manager')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('brigade_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brigade_id')->constrained('brigades')->cascadeOnDelete();
            $table->string('title');
            $table->string('document_type');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('verification_status')->default('pending');
            $table->timestampTz('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('brigade_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contractor_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('specialization_name')->nullable();
            $table->string('city')->nullable();
            $table->unsignedInteger('team_size_min')->nullable();
            $table->unsignedInteger('team_size_max')->nullable();
            $table->string('status')->default('open');
            $table->timestampTz('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('brigade_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('brigade_requests')->cascadeOnDelete();
            $table->foreignId('brigade_id')->constrained('brigades')->cascadeOnDelete();
            $table->text('cover_message')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->unique(['request_id', 'brigade_id']);
        });

        Schema::create('brigade_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brigade_id')->constrained('brigades')->cascadeOnDelete();
            $table->foreignId('contractor_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->text('message')->nullable();
            $table->string('status')->default('pending');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('brigade_project_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brigade_id')->constrained('brigades')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('contractor_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('status')->default('planned');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brigade_project_assignments');
        Schema::dropIfExists('brigade_invitations');
        Schema::dropIfExists('brigade_responses');
        Schema::dropIfExists('brigade_requests');
        Schema::dropIfExists('brigade_documents');
        Schema::dropIfExists('brigade_members');
        Schema::dropIfExists('brigade_profile_specialization');
        Schema::dropIfExists('brigade_specializations');
        Schema::dropIfExists('brigades');
    }
};
