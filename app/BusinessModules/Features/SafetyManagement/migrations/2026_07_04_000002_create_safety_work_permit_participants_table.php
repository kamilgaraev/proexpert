<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_work_permit_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permit_id')->constrained('safety_work_permits')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('workforce_employees')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('external_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('role_name')->nullable();
            $table->string('position_name')->nullable();
            $table->string('work_category', 80)->nullable();
            $table->string('admission_status', 40)->default('pending');
            $table->timestampTz('admission_checked_at')->nullable();
            $table->jsonb('admission_blockers')->nullable();
            $table->jsonb('admission_warnings')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'permit_id']);
            $table->index(['organization_id', 'employee_id', 'admission_status']);
            $table->index(['permit_id', 'admission_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_work_permit_participants');
    }
};
