<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('production_labor_timesheet_entries', function (Blueprint $table): void {
            $table->foreignId('employee_id')
                ->nullable()
                ->after('user_id')
                ->constrained('workforce_employees')
                ->nullOnDelete();
            $table->boolean('include_in_payroll')
                ->default(true)
                ->after('employee_id');

            $table->index(['organization_id', 'employee_id']);
            $table->index(['organization_id', 'include_in_payroll']);
        });
    }

    public function down(): void
    {
        Schema::table('production_labor_timesheet_entries', function (Blueprint $table): void {
            $table->dropForeign(['employee_id']);
            $table->dropIndex(['organization_id', 'employee_id']);
            $table->dropIndex(['organization_id', 'include_in_payroll']);
            $table->dropColumn(['employee_id', 'include_in_payroll']);
        });
    }
};
