<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_model_session_event_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('design_model_sessions')->cascadeOnDelete();
            $table->foreignId('model_set_revision_id')->constrained('design_model_set_revisions')->restrictOnDelete();
            $table->string('client_id', 100);
            $table->unsignedBigInteger('user_id');
            $table->jsonb('sequences');
            $table->bigInteger('max_sequence')->default(-1);
            $table->unsignedBigInteger('leave_sequence')->nullable();
            $table->unique(['organization_id', 'session_id', 'model_set_revision_id', 'client_id'], 'design_session_event_order_client_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_model_session_event_orders');
    }
};
