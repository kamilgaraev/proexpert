<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('site_requests', 'idempotency_key')) {
            Schema::table('site_requests', function (Blueprint $table): void {
                $table->string('idempotency_key', 128)->nullable();
                $table->string('idempotency_hash', 64)->nullable();
                $table->unique(['organization_id', 'user_id', 'idempotency_key'], 'site_requests_mobile_idempotency_unique');
            });
        }

        if (! Schema::hasColumn('site_request_groups', 'idempotency_key')) {
            Schema::table('site_request_groups', function (Blueprint $table): void {
                $table->string('idempotency_key', 128)->nullable();
                $table->string('idempotency_hash', 64)->nullable();
                $table->unique(['organization_id', 'user_id', 'idempotency_key'], 'site_request_groups_mobile_idempotency_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('site_requests', 'idempotency_key')) {
            Schema::table('site_requests', function (Blueprint $table): void {
                $table->dropUnique('site_requests_mobile_idempotency_unique');
                $table->dropColumn(['idempotency_key', 'idempotency_hash']);
            });
        }
        if (Schema::hasColumn('site_request_groups', 'idempotency_key')) {
            Schema::table('site_request_groups', function (Blueprint $table): void {
                $table->dropUnique('site_request_groups_mobile_idempotency_unique');
                $table->dropColumn(['idempotency_key', 'idempotency_hash']);
            });
        }
    }
};
