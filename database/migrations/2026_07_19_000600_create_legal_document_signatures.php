<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('legal_signature_requests')) {
            Schema::create('legal_signature_requests', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('document_id');
                $table->unsignedBigInteger('document_version_id');
                $table->unsignedBigInteger('party_id')->nullable();
                $table->string('method', 32);
                $table->string('provider', 128)->nullable();
                $table->string('status', 32);
                $table->char('signed_content_hash', 64);
                $table->jsonb('signers');
                $table->char('correlation_id', 64);
                $table->string('provider_request_id', 255)->nullable();
                $table->char('callback_replay_hash', 64)->nullable();
                $table->char('callback_payload_hash', 64)->nullable();
                $table->jsonb('session_metadata')->nullable();
                $table->string('idempotency_key', 191);
                $table->char('request_hash', 64);
                $table->unsignedBigInteger('requested_by_user_id');
                $table->timestampTz('requested_at');
                $table->timestampTz('expires_at')->nullable();
                $table->timestampTz('completed_at')->nullable();
                $table->timestampsTz();
            });
        }
        if (! Schema::hasTable('legal_document_signatures')) {
            Schema::create('legal_document_signatures', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('document_id');
                $table->unsignedBigInteger('document_version_id');
                $table->unsignedBigInteger('signature_request_id');
                $table->unsignedBigInteger('party_id')->nullable();
                $table->string('method', 32);
                $table->string('provider', 128)->nullable();
                $table->string('signer_name', 255)->nullable();
                $table->jsonb('signers');
                $table->char('signed_content_hash', 64);
                $table->text('signature_path')->nullable();
                $table->char('signature_content_hash', 64)->nullable();
                $table->jsonb('certificate_metadata');
                $table->jsonb('provider_metadata');
                $table->text('storage_location')->nullable();
                $table->timestampTz('signed_at');
                $table->timestampTz('verified_at')->nullable();
                $table->string('verification_status', 32);
                $table->text('revocation_reason')->nullable();
                $table->unsignedBigInteger('registered_by_user_id')->nullable();
                $table->string('idempotency_key', 191);
                $table->char('request_hash', 64);
                $table->timestampsTz();
            });
        }
        if (! Schema::hasTable('legal_signature_verifications')) {
            Schema::create('legal_signature_verifications', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('document_id');
                $table->unsignedBigInteger('document_version_id');
                $table->unsignedBigInteger('signature_id');
                $table->string('provider', 128);
                $table->string('status', 32);
                $table->char('signed_content_hash', 64);
                $table->jsonb('certificate_metadata');
                $table->jsonb('provider_metadata');
                $table->text('revocation_reason')->nullable();
                $table->unsignedBigInteger('verified_by_user_id')->nullable();
                $table->timestampTz('verified_at');
                $table->string('idempotency_key', 191);
                $table->char('request_hash', 64);
                $table->timestampsTz();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_signature_migrations_are_forward_only');
    }
};
