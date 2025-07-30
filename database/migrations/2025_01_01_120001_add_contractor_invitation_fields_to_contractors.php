<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            $table->unsignedBigInteger('source_organization_id')->nullable()->after('organization_id');
            $table->enum('contractor_type', ['manual', 'invited_organization'])->default('manual')->after('notes');
            $table->unsignedBigInteger('contractor_invitation_id')->nullable()->after('contractor_type');
            $table->timestamp('connected_at')->nullable()->after('contractor_invitation_id');
            $table->json('sync_settings')->nullable()->after('connected_at');
            $table->timestamp('last_sync_at')->nullable()->after('sync_settings');

            $table->foreign('source_organization_id')->references('id')->on('organizations')->onDelete('set null');
            $table->foreign('contractor_invitation_id')->references('id')->on('contractor_invitations')->onDelete('set null');
            
            $table->index(['organization_id', 'contractor_type']);
            $table->index(['source_organization_id', 'contractor_type']);
            $table->index('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            $table->dropForeign(['source_organization_id']);
            $table->dropForeign(['contractor_invitation_id']);
            $table->dropColumn([
                'source_organization_id',
                'contractor_type', 
                'contractor_invitation_id',
                'connected_at',
                'sync_settings',
                'last_sync_at'
            ]);
        });
    }
};