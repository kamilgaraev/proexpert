<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('reporter_organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('disputed_organization_id')->constrained('organizations')->onDelete('cascade');
            $table->string('dispute_type')->default('fraudulent_registration');
            $table->text('reason');
            $table->json('evidence')->nullable();
            $table->string('status')->default('under_investigation');
            $table->string('priority')->default('high');
            $table->foreignId('assigned_to_moderator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('moderator_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution')->nullable();
            $table->json('actions_taken')->nullable();
            $table->timestamps();
            
            $table->index(['disputed_organization_id', 'status']);
            $table->index(['status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_disputes');
    }
};

