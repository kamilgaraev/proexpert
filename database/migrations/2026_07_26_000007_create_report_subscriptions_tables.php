<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_subscriptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('owner_id');
            $table->ulid('saved_view_id');
            $table->string('report_code', 64);
            $table->string('frequency', 16);
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->time('local_time');
            $table->string('timezone', 64);
            $table->jsonb('period_policy_json');
            $table->string('format', 8);
            $table->string('channel', 16)->default('in_app');
            $table->string('status', 16)->default('active');
            $table->string('disabled_reason', 64)->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestampTz('next_run_at')->nullable();
            $table->text('execution_input_bytes');
            $table->char('execution_input_sha256', 64);
            $table->char('definition_sha256', 64);
            $table->string('contract_version', 32);
            $table->unsignedBigInteger('transition_version')->default(1);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['status', 'next_run_at', 'id'], 'report_subscriptions_due_index');
            $table->index(['organization_id', 'owner_id', 'next_run_at', 'id'], 'report_subscriptions_owner_cursor_index');
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('owner_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('saved_view_id')->references('id')->on('report_saved_views')->restrictOnDelete();
        });
        Schema::create('report_subscription_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('owner_id');
            $table->ulid('subscription_id');
            $table->string('trigger', 16);
            $table->char('trigger_key_hash', 64)->nullable();
            $table->char('manual_request_sha256', 64)->nullable();
            $table->timestampTz('scheduled_for');
            $table->text('execution_input_bytes');
            $table->char('execution_input_sha256', 64);
            $table->unsignedBigInteger('subscription_version');
            $table->ulid('run_id')->nullable();
            $table->ulid('export_id')->nullable();
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->string('status', 24)->default('scheduled');
            $table->char('notification_key_hash', 64)->nullable();
            $table->string('notification_receipt_id', 128)->nullable();
            $table->timestampTz('notified_at')->nullable();
            $table->string('safe_error_code', 64)->nullable();
            $table->timestampTz('retry_at')->nullable();
            $table->timestampTz('execution_expires_at');
            $table->timestampTz('retention_delete_after');
            $table->timestampsTz();
            $table->unique(['notification_key_hash'], 'report_subscription_delivery_notification_unique');
            $table->index(['status', 'retry_at', 'id'], 'report_subscription_delivery_dispatch_index');
            $table->index(['status', 'execution_expires_at', 'id'], 'report_subscription_execution_expiry_index');
            $table->index(['retention_delete_after', 'id'], 'report_subscription_retention_index');
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('owner_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('subscription_id')->references('id')->on('report_subscriptions')->restrictOnDelete();
        });
        Schema::create('report_subscription_notification_receipts', function (Blueprint $table): void {
            $table->string('id', 128)->primary();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->ulid('delivery_id');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('organization_id');
            $table->uuid('notification_id')->nullable()->unique();
            $table->timestampsTz();
            $table->foreign('delivery_id')->references('id')->on('report_subscription_deliveries')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX report_subscription_manual_idempotency_unique ON report_subscription_deliveries (subscription_id, trigger_key_hash) WHERE trigger_key_hash IS NOT NULL');
        DB::statement("CREATE UNIQUE INDEX report_subscription_calendar_schedule_unique ON report_subscription_deliveries (subscription_id, scheduled_for) WHERE trigger = 'calendar'");
        DB::statement("ALTER TABLE report_subscriptions ADD CONSTRAINT report_subscriptions_status_check CHECK (status IN ('active','paused','disabled','deleted')), ADD CONSTRAINT report_subscriptions_channel_check CHECK (channel = 'in_app')");
        DB::statement("ALTER TABLE report_subscription_deliveries ADD CONSTRAINT report_subscription_deliveries_status_check CHECK (status IN ('scheduled','building_run','building_export','ready','notified','failed','expired')), ADD CONSTRAINT report_subscription_deliveries_manual_hash_check CHECK ((trigger = 'manual' AND trigger_key_hash IS NOT NULL AND manual_request_sha256 IS NOT NULL) OR (trigger = 'calendar' AND trigger_key_hash IS NULL AND manual_request_sha256 IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_subscription_notification_receipts');
        Schema::dropIfExists('report_subscription_deliveries');
        Schema::dropIfExists('report_subscriptions');
    }
};
