<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machinery_shift_reports', function (Blueprint $table): void {
            $table->foreignId('cancelled_by_user_id')->nullable()->after('finished_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('finished_at');
            $table->text('cancellation_reason')->nullable()->after('rejection_reason');
        });

        Schema::table('machinery_fuel_issues', function (Blueprint $table): void {
            $table->foreignId('reversal_movement_id')->nullable()->after('warehouse_movement_id')->constrained('warehouse_movements')->restrictOnDelete();
            $table->foreignId('cancelled_by_user_id')->nullable()->after('issued_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('issued_at');
            $table->text('cancellation_reason')->nullable()->after('comment');
            $table->unique('reversal_movement_id', 'machinery_fuel_reversal_unique');
            $table->index(['shift_report_id', 'cancelled_at'], 'machinery_fuel_shift_cancelled_index');
        });
    }

    public function down(): void
    {
        Schema::table('machinery_fuel_issues', function (Blueprint $table): void {
            $table->dropUnique('machinery_fuel_reversal_unique');
            $table->dropIndex('machinery_fuel_shift_cancelled_index');
            $table->dropConstrainedForeignId('reversal_movement_id');
            $table->dropConstrainedForeignId('cancelled_by_user_id');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });

        Schema::table('machinery_shift_reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by_user_id');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
