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
        Schema::table('mdm_change_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('mdm_change_requests', 'uuid')) {
                $table->uuid('uuid')->nullable()->unique('mdm_change_requests_uuid_unique');
            }

            if (! Schema::hasColumn('mdm_change_requests', 'mdm_record_id')) {
                $table->foreignId('mdm_record_id')->nullable()->constrained('mdm_records')->nullOnDelete();
            }

            foreach ([
                'priority' => ['string', 32, 'normal'],
                'title' => ['string', 255, null],
                'field_policy_version' => ['string', 40, null],
                'idempotency_key' => ['string', 160, null],
                'payload_hash' => ['string', 64, null],
            ] as $column => [$type, $length, $default]) {
                if (Schema::hasColumn('mdm_change_requests', $column)) {
                    continue;
                }

                $definition = $table->{$type}($column, $length)->nullable();
                if ($default !== null) {
                    $definition->default($default);
                }
            }

            foreach (['reason', 'business_justification', 'failure_reason', 'review_note', 'apply_note', 'cancel_reason'] as $column) {
                if (! Schema::hasColumn('mdm_change_requests', $column)) {
                    $table->text($column)->nullable();
                }
            }

            foreach ([
                'diff',
                'impact_snapshot',
                'validation_snapshot',
                'one_c_lock_summary',
                'rollback_snapshot',
                'apply_result',
            ] as $column) {
                if (! Schema::hasColumn('mdm_change_requests', $column)) {
                    $table->jsonb($column)->nullable();
                }
            }

            foreach ([
                'owner_user_id',
                'approver_user_id',
                'executor_user_id',
                'cancelled_by_user_id',
            ] as $column) {
                if (! Schema::hasColumn('mdm_change_requests', $column)) {
                    $table->foreignId($column)->nullable()->constrained('users')->nullOnDelete();
                }
            }

            foreach ([
                'submitted_at',
                'under_review_at',
                'approved_at',
                'rejected_at',
                'applied_at',
                'failed_at',
                'cancelled_at',
            ] as $column) {
                if (! Schema::hasColumn('mdm_change_requests', $column)) {
                    $table->timestamp($column)->nullable();
                }
            }

            if (! Schema::hasColumn('mdm_change_requests', 'expected_record_version')) {
                $table->unsignedInteger('expected_record_version')->nullable();
            }

            $table->index(['organization_id', 'status', 'priority'], 'mdm_change_requests_workflow_idx');
            $table->index(['organization_id', 'owner_user_id', 'status'], 'mdm_change_requests_owner_idx');
            $table->index(['organization_id', 'idempotency_key'], 'mdm_change_requests_idempotency_idx');
            $table->index(['organization_id', 'payload_hash'], 'mdm_change_requests_payload_hash_idx');
        });

        DB::statement(
            "CREATE UNIQUE INDEX mdm_change_requests_active_idempotency_unique
            ON mdm_change_requests (organization_id, idempotency_key)
            WHERE idempotency_key IS NOT NULL
            AND status NOT IN ('rejected', 'applied', 'failed', 'cancelled')"
        );

        DB::statement(
            "CREATE UNIQUE INDEX mdm_change_requests_active_payload_hash_unique
            ON mdm_change_requests (organization_id, payload_hash)
            WHERE payload_hash IS NOT NULL
            AND status NOT IN ('rejected', 'applied', 'failed', 'cancelled')"
        );

        Schema::create('mdm_change_request_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('change_request_id')->constrained('mdm_change_requests')->cascadeOnDelete();
            $table->string('event_type', 80);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('before_status', 40)->nullable();
            $table->string('after_status', 40)->nullable();
            $table->text('comment')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'change_request_id', 'created_at'], 'mdm_change_request_events_timeline_idx');
            $table->index(['organization_id', 'event_type'], 'mdm_change_request_events_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdm_change_request_events');

        DB::statement('DROP INDEX IF EXISTS mdm_change_requests_active_idempotency_unique');
        DB::statement('DROP INDEX IF EXISTS mdm_change_requests_active_payload_hash_unique');

        Schema::table('mdm_change_requests', function (Blueprint $table): void {
            $table->dropIndex('mdm_change_requests_workflow_idx');
            $table->dropIndex('mdm_change_requests_owner_idx');
            $table->dropIndex('mdm_change_requests_idempotency_idx');
            $table->dropIndex('mdm_change_requests_payload_hash_idx');

            $table->dropUnique('mdm_change_requests_uuid_unique');
            $table->dropForeign(['mdm_record_id']);
            foreach (['owner_user_id', 'approver_user_id', 'executor_user_id', 'cancelled_by_user_id'] as $column) {
                $table->dropForeign([$column]);
            }

            $table->dropColumn([
                'uuid',
                'mdm_record_id',
                'priority',
                'title',
                'reason',
                'business_justification',
                'diff',
                'field_policy_version',
                'impact_snapshot',
                'validation_snapshot',
                'one_c_lock_summary',
                'rollback_snapshot',
                'apply_result',
                'failure_reason',
                'owner_user_id',
                'approver_user_id',
                'executor_user_id',
                'cancelled_by_user_id',
                'submitted_at',
                'under_review_at',
                'approved_at',
                'rejected_at',
                'applied_at',
                'failed_at',
                'cancelled_at',
                'apply_note',
                'cancel_reason',
                'expected_record_version',
                'idempotency_key',
                'payload_hash',
            ]);
        });
    }
};
