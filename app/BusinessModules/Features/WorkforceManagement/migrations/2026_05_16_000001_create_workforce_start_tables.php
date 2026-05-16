<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workforce_employees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('personnel_number', 80);
            $table->string('last_name');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('employment_status', 40)->default('active');
            $table->date('hire_date');
            $table->date('dismissal_date')->nullable();
            $table->string('external_payroll_ref', 120)->nullable();
            $table->string('phone', 80)->nullable();
            $table->string('email')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'personnel_number']);
            $table->unique(['organization_id', 'external_payroll_ref']);
            $table->index(['organization_id', 'employment_status']);
            $table->index(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_employees');
    }
};
