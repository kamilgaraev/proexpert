<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_performance_acts', function (Blueprint $table): void {
            if (!Schema::hasColumn('contract_performance_acts', 'period_start')) {
                $table->date('period_start')->nullable()->after('act_date');
            }

            if (!Schema::hasColumn('contract_performance_acts', 'period_end')) {
                $table->date('period_end')->nullable()->after('period_start');
            }

            if (!Schema::hasColumn('contract_performance_acts', 'status')) {
                $table->string('status', 32)->default('draft')->after('description');
            }

            if (!Schema::hasColumn('contract_performance_acts', 'created_by_user_id')) {
                $table->foreignId('created_by_user_id')->nullable()->after('approval_date')->constrained('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('contract_performance_acts', 'submitted_by_user_id')) {
                $table->foreignId('submitted_by_user_id')->nullable()->after('created_by_user_id')->constrained('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('contract_performance_acts', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('submitted_by_user_id');
            }

            if (!Schema::hasColumn('contract_performance_acts', 'approved_by_user_id')) {
                $table->foreignId('approved_by_user_id')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('contract_performance_acts', 'rejected_by_user_id')) {
                $table->foreignId('rejected_by_user_id')->nullable()->after('approved_by_user_id')->constrained('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('contract_performance_acts', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('rejected_by_user_id');
            }

            if (!Schema::hasColumn('contract_performance_acts', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('rejected_at');
            }

            if (!Schema::hasColumn('contract_performance_acts', 'signed_file_id')) {
                $table->foreignId('signed_file_id')->nullable()->after('rejection_reason')->constrained('files')->nullOnDelete();
            }

            if (!Schema::hasColumn('contract_performance_acts', 'signed_by_user_id')) {
                $table->foreignId('signed_by_user_id')->nullable()->after('signed_file_id')->constrained('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('contract_performance_acts', 'signed_at')) {
                $table->timestamp('signed_at')->nullable()->after('signed_by_user_id');
            }

            if (!Schema::hasColumn('contract_performance_acts', 'locked_by_user_id')) {
                $table->foreignId('locked_by_user_id')->nullable()->after('signed_at')->constrained('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('contract_performance_acts', 'locked_at')) {
                $table->timestamp('locked_at')->nullable()->after('locked_by_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contract_performance_acts', function (Blueprint $table): void {
            foreach ([
                'created_by_user_id',
                'submitted_by_user_id',
                'approved_by_user_id',
                'rejected_by_user_id',
                'signed_file_id',
                'signed_by_user_id',
                'locked_by_user_id',
            ] as $column) {
                if (Schema::hasColumn('contract_performance_acts', $column)) {
                    $table->dropForeign([$column]);
                }
            }

            foreach ([
                'locked_at',
                'locked_by_user_id',
                'signed_at',
                'signed_by_user_id',
                'signed_file_id',
                'rejection_reason',
                'rejected_at',
                'rejected_by_user_id',
                'approved_by_user_id',
                'submitted_at',
                'submitted_by_user_id',
                'created_by_user_id',
                'status',
                'period_end',
                'period_start',
            ] as $column) {
                if (Schema::hasColumn('contract_performance_acts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
