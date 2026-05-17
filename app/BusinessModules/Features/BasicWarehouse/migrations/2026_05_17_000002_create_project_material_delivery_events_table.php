<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_material_delivery_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_material_delivery_id')
                ->constrained('project_material_deliveries')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 80);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->decimal('quantity', 15, 3)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['project_material_delivery_id', 'occurred_at'], 'idx_pmde_delivery_date');
            $table->index('user_id', 'idx_pmde_user');
            $table->index('event_type', 'idx_pmde_event_type');
            $table->index('occurred_at', 'idx_pmde_occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_material_delivery_events');
    }
};
