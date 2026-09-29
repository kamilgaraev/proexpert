<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_assistant_report_access', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('storage_path', 1024)->index();
            $table->jsonb('source_refs');
            $table->jsonb('required_domains');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::table('project_pulse_reports', function (Blueprint $table): void {
            $table->jsonb('source_refs')->nullable();
            $table->jsonb('required_domains')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_report_access');
        Schema::table('project_pulse_reports', function (Blueprint $table): void {
            $table->dropColumn(['source_refs', 'required_domains']);
        });
    }
};
