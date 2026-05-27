<?php

declare(strict_types=1);

use App\Models\ContactForm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_forms', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('assigned_system_admin_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('system_admins')
                ->nullOnDelete();
            $table->string('priority', 40)->default(ContactForm::PRIORITY_NORMAL)->after('status');
            $table->string('channel', 60)->default(ContactForm::CHANNEL_PUBLIC_FORM)->after('priority');
            $table->jsonb('internal_notes')->nullable()->after('channel');
            $table->timestampTz('last_activity_at')->nullable()->after('internal_notes');
            $table->timestampTz('escalated_at')->nullable()->after('last_activity_at');
            $table->foreignId('escalated_by_system_admin_id')
                ->nullable()
                ->after('escalated_at')
                ->constrained('system_admins')
                ->nullOnDelete();

            $table->index(['priority', 'status']);
            $table->index(['assigned_system_admin_id', 'status'], 'contact_forms_assignee_status_idx');
            $table->index(['organization_id', 'created_at']);
        });

        DB::table('contact_forms')
            ->whereNull('last_activity_at')
            ->update([
                'last_activity_at' => DB::raw('COALESCE(updated_at, created_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('contact_forms', function (Blueprint $table): void {
            $table->dropIndex(['priority', 'status']);
            $table->dropIndex('contact_forms_assignee_status_idx');
            $table->dropIndex(['organization_id', 'created_at']);
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['assigned_system_admin_id']);
            $table->dropForeign(['escalated_by_system_admin_id']);
            $table->dropColumn([
                'organization_id',
                'assigned_system_admin_id',
                'priority',
                'channel',
                'internal_notes',
                'last_activity_at',
                'escalated_at',
                'escalated_by_system_admin_id',
            ]);
        });
    }
};
