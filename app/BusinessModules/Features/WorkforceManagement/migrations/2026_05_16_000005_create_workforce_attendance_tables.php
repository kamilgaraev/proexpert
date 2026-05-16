<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workforce_attendance_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date');
            $table->string('status', 40);
            $table->decimal('hours', 5, 2)->nullable();
            $table->string('reason', 500);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['organization_id', 'work_date']);
            $table->index(['employee_id', 'work_date']);
            $table->index(['project_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_attendance_corrections');
    }
};
