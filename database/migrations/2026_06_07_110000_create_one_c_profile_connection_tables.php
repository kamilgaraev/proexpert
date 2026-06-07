<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('one_c_bases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('name', 160);
            $table->string('environment', 32)->default('production');
            $table->string('connector', 32)->default('http');
            $table->text('endpoint_url_encrypted')->nullable();
            $table->string('metadata_path', 160)->default('/metadata');
            $table->string('endpoint_fingerprint', 64)->nullable();
            $table->string('protocol_version', 64)->nullable();
            $table->string('connector_version', 64)->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('connection_status', 32)->default('untested');
            $table->timestampTz('last_connection_check_at')->nullable();
            $table->string('last_connection_check_code', 64)->nullable();
            $table->timestampTz('last_successful_exchange_at')->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->default(15);
            $table->unsignedSmallInteger('connect_timeout_seconds')->default(5);
            $table->jsonb('retry_policy')->nullable();
            $table->jsonb('supported_scopes')->nullable();
            $table->jsonb('warning_codes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['organization_id', 'environment', 'code'], 'one_c_bases_org_env_code_uniq');
            $table->index(['organization_id', 'environment', 'status'], 'one_c_bases_org_env_status_idx');
            $table->index(['organization_id', 'connection_status'], 'one_c_bases_org_connection_status_idx');
        });

        Schema::create('one_c_integration_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('one_c_base_id')->constrained('one_c_bases')->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('name', 160);
            $table->string('environment', 32)->default('production');
            $table->string('auth_type', 32)->default('bearer_token');
            $table->string('exchange_mode', 32)->default('manual');
            $table->string('status', 32)->default('draft');
            $table->string('status_reason_code', 80)->nullable();
            $table->boolean('is_default_for_legal_entity')->default(false);
            $table->integer('routing_priority')->default(100);
            $table->jsonb('allowed_scopes')->nullable();
            $table->string('connection_status', 32)->default('untested');
            $table->timestampTz('last_connection_check_at')->nullable();
            $table->string('last_connection_check_code', 64)->nullable();
            $table->string('protocol_version', 64)->nullable();
            $table->string('connector_version', 64)->nullable();
            $table->jsonb('supported_scopes')->nullable();
            $table->jsonb('warning_codes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['organization_id', 'environment', 'code'], 'one_c_profiles_org_env_code_uniq');
            $table->index(['organization_id', 'status'], 'one_c_profiles_org_status_idx');
            $table->index(['organization_id', 'one_c_base_id'], 'one_c_profiles_org_base_idx');
            $table->index(['organization_id', 'connection_status'], 'one_c_profiles_org_connection_status_idx');
        });

        Schema::create('one_c_profile_secrets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('one_c_integration_profile_id')
                ->constrained('one_c_integration_profiles')
                ->cascadeOnDelete();
            $table->string('type', 32)->default('bearer_token');
            $table->string('label', 120);
            $table->text('secret_value_encrypted')->nullable();
            $table->text('username_encrypted')->nullable();
            $table->text('password_encrypted')->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->string('status', 32)->default('active');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('rotated_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(
                ['organization_id', 'one_c_integration_profile_id', 'status'],
                'one_c_profile_secrets_profile_status_idx'
            );
        });

        Schema::create('one_c_profile_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('one_c_integration_profile_id')
                ->constrained('one_c_integration_profiles')
                ->cascadeOnDelete();
            $table->foreignId('one_c_base_id')->nullable()->constrained('one_c_bases')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 80);
            $table->string('result_code', 64)->nullable();
            $table->string('result_status', 32)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->jsonb('safe_context')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(
                ['organization_id', 'one_c_integration_profile_id', 'created_at'],
                'one_c_profile_audit_profile_created_idx'
            );
            $table->index(['organization_id', 'result_code'], 'one_c_profile_audit_result_code_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('one_c_profile_audit_events');
        Schema::dropIfExists('one_c_profile_secrets');
        Schema::dropIfExists('one_c_integration_profiles');
        Schema::dropIfExists('one_c_bases');
    }
};
