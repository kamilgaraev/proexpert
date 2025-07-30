<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractor_invitations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('invited_organization_id');
            $table->unsignedBigInteger('invited_by_user_id');
            $table->string('token', 64)->unique();
            $table->enum('status', ['pending', 'accepted', 'declined', 'expired'])->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('accepted_by_user_id')->nullable();
            $table->text('invitation_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('invited_organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('invited_by_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('accepted_by_user_id')->references('id')->on('users')->onDelete('set null');

            $table->index(['organization_id', 'status']);
            $table->index(['invited_organization_id', 'status']);
            $table->index('expires_at');
            $table->index('created_at');
            $table->index(['organization_id', 'invited_organization_id']);
            
            $table->unique(['organization_id', 'invited_organization_id', 'status'], 'unique_active_invitation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_invitations');
    }
};