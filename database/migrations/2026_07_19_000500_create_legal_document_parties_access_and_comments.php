<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_document_parties', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('party_organization_id')->nullable();
            $table->unsignedBigInteger('counterparty_id')->nullable();
            $table->string('party_role', 64);
            $table->string('legal_name', 512);
            $table->string('tax_number', 32)->nullable();
            $table->string('registration_number', 64)->nullable();
            $table->text('legal_address')->nullable();
            $table->jsonb('bank_details')->nullable();
            $table->string('representative_name', 255)->nullable();
            $table->string('representative_position', 255)->nullable();
            $table->text('authority_basis')->nullable();
            $table->string('data_source', 32);
            $table->jsonb('snapshot');
            $table->timestampsTz();
        });
        Schema::create('legal_document_access_grants', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('subject_organization_id');
            $table->unsignedBigInteger('subject_user_id')->nullable();
            $table->jsonb('abilities');
            $table->unsignedBigInteger('granted_by_user_id');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestampsTz();
        });
        Schema::create('legal_document_comments', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id');
            $table->unsignedBigInteger('author_user_id');
            $table->text('body');
            $table->unsignedInteger('page_number')->nullable();
            $table->jsonb('anchor')->nullable();
            $table->string('visibility', 32);
            $table->boolean('is_blocking')->default(false);
            $table->string('status', 16)->default('open');
            $table->text('resolution')->nullable();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->string('resolution_idempotency_key', 191)->nullable();
            $table->char('resolution_request_hash', 64)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_access_migrations_are_forward_only');
    }
};
