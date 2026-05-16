<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workforce_attendance_qr_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date');
            $table->string('token_hash', 128)->unique();
            $table->string('payload_hash', 128);
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('used_at')->nullable();
            $table->foreignId('used_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 40)->default('active');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['organization_id', 'employee_id', 'work_date']);
            $table->index(['organization_id', 'project_id', 'work_date']);
            $table->index(['organization_id', 'status', 'expires_at']);
        });

        Schema::create('workforce_attendance_scan_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('qr_token_id')->nullable()->constrained('workforce_attendance_qr_tokens')->nullOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('workforce_employees')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scanned_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->date('work_date')->nullable();
            $table->string('result', 40);
            $table->string('result_label');
            $table->string('failure_reason')->nullable();
            $table->string('device_id')->nullable();
            $table->timestampTz('scanned_at');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'project_id', 'scanned_at']);
            $table->index(['organization_id', 'employee_id', 'work_date']);
            $table->index(['organization_id', 'result', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_attendance_scan_events');
        Schema::dropIfExists('workforce_attendance_qr_tokens');
    }
};
