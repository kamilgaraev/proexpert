<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_work_plans', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'schedule_id', 'work_date']);
            $table->foreignId('accepted_by_user_id')->nullable()->after('accepted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable()->after('accepted_by_user_id');
            $table->foreignId('returned_by_user_id')->nullable()->after('returned_at')->constrained('users')->nullOnDelete();
            $table->text('return_reason')->nullable()->after('returned_by_user_id');
            $table->timestamp('closed_at')->nullable()->after('return_reason');
            $table->foreignId('closed_by_user_id')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            $table->foreignId('revision_of_daily_plan_id')->nullable()->after('closed_by_user_id')->constrained('daily_work_plans')->nullOnDelete();
            $table->unsignedInteger('revision_number')->default(1)->after('revision_of_daily_plan_id');
            $table->timestamp('revised_at')->nullable()->after('revision_number');
            $table->foreignId('revised_by_user_id')->nullable()->after('revised_at')->constrained('users')->nullOnDelete();
            $table->text('revision_reason')->nullable()->after('revised_by_user_id');

            $table->index(['organization_id', 'revision_of_daily_plan_id']);
            $table->unique(
                ['organization_id', 'schedule_id', 'work_date', 'revision_number'],
                'daily_work_plans_schedule_work_date_revision_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('daily_work_plans', function (Blueprint $table): void {
            $table->dropUnique('daily_work_plans_schedule_work_date_revision_unique');
            $table->dropIndex(['organization_id', 'revision_of_daily_plan_id']);
            $table->dropConstrainedForeignId('accepted_by_user_id');
            $table->dropConstrainedForeignId('returned_by_user_id');
            $table->dropConstrainedForeignId('closed_by_user_id');
            $table->dropConstrainedForeignId('revision_of_daily_plan_id');
            $table->dropConstrainedForeignId('revised_by_user_id');
            $table->dropColumn([
                'returned_at',
                'return_reason',
                'closed_at',
                'revision_number',
                'revised_at',
                'revision_reason',
            ]);
            $table->unique(['organization_id', 'schedule_id', 'work_date']);
        });
    }
};
