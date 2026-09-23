<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_management_rfis', function (Blueprint $table): void {
            $table->foreignId('recipient_organization_id')->nullable()->after('organization_id')
                ->constrained('organizations')->nullOnDelete();
            $table->index(['project_id', 'recipient_organization_id', 'status'], 'change_rfi_recipient_status_idx');
        });

        Schema::create('change_management_rfi_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rfi_id')->constrained('change_management_rfis')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('event', 60);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->text('message')->nullable();
            $table->jsonb('attachments')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->index(['rfi_id', 'id']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('change_management_rfi_history');
        Schema::table('change_management_rfis', function (Blueprint $table): void {
            $table->dropIndex('change_rfi_recipient_status_idx');
            $table->dropConstrainedForeignId('recipient_organization_id');
        });
    }
};
