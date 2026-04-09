<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_participant_invitations', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable()->after('accepted_at');
            $table->foreignId('cancelled_by_user_id')->nullable()->after('cancelled_at')->constrained('users');
            $table->timestamp('resent_at')->nullable()->after('cancelled_by_user_id');
            $table->unsignedBigInteger('accepted_organization_id_snapshot')->nullable()->after('invited_organization_id');
            $table->string('status_reason')->nullable()->after('status');
            $table->index(['status', 'expires_at'], 'project_participant_invites_status_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::table('project_participant_invitations', function (Blueprint $table): void {
            $table->dropIndex('project_participant_invites_status_expires_idx');
            $table->dropConstrainedForeignId('cancelled_by_user_id');
            $table->dropColumn([
                'cancelled_at',
                'resent_at',
                'accepted_organization_id_snapshot',
                'status_reason',
            ]);
        });
    }
};
