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
        Schema::table('safety_briefings', function (Blueprint $table): void {
            $table->string('status', 40)->default('awaiting_signatures');
            $table->dateTimeTz('started_at')->nullable();
            $table->dateTimeTz('signature_deadline_at')->nullable();
            $table->dateTimeTz('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTimeTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->jsonb('signature_summary')->nullable();

            $table->index(['organization_id', 'status', 'conducted_at']);
            $table->index(['status', 'signature_deadline_at']);
            $table->index(['organization_id', 'status', 'signature_deadline_at']);
        });

        Schema::table('safety_briefing_participants', function (Blueprint $table): void {
            $table->foreignId('employee_id')->nullable()->constrained('workforce_employees')->nullOnDelete();
            $table->string('signature_status', 40)->default('pending');
            $table->foreignId('signed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signature_method', 80)->nullable();
            $table->text('refusal_reason')->nullable();
            $table->text('absence_reason')->nullable();
            $table->jsonb('signature_metadata')->nullable();

            $table->index(['briefing_id', 'signature_status']);
            $table->index(['employee_id', 'signature_status']);
            $table->index(['signed_by_user_id', 'signature_status']);
        });

        DB::table('safety_briefings')
            ->whereNull('started_at')
            ->update(['started_at' => DB::raw('conducted_at')]);

        DB::table('safety_briefing_participants')
            ->whereNotNull('signed_at')
            ->update(['signature_status' => 'signed']);
    }

    public function down(): void
    {
        Schema::table('safety_briefing_participants', function (Blueprint $table): void {
            $table->dropForeign(['employee_id']);
            $table->dropForeign(['signed_by_user_id']);
            $table->dropIndex(['briefing_id', 'signature_status']);
            $table->dropIndex(['employee_id', 'signature_status']);
            $table->dropIndex(['signed_by_user_id', 'signature_status']);
            $table->dropColumn([
                'employee_id',
                'signature_status',
                'signed_by_user_id',
                'signature_method',
                'refusal_reason',
                'absence_reason',
                'signature_metadata',
            ]);
        });

        Schema::table('safety_briefings', function (Blueprint $table): void {
            $table->dropForeign(['completed_by_user_id']);
            $table->dropForeign(['cancelled_by_user_id']);
            $table->dropIndex(['organization_id', 'status', 'conducted_at']);
            $table->dropIndex(['status', 'signature_deadline_at']);
            $table->dropIndex(['organization_id', 'status', 'signature_deadline_at']);
            $table->dropColumn([
                'status',
                'started_at',
                'signature_deadline_at',
                'completed_at',
                'completed_by_user_id',
                'cancelled_at',
                'cancelled_by_user_id',
                'cancellation_reason',
                'signature_summary',
            ]);
        });
    }
};
