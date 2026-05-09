<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_generation_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('estimate_generation_sessions')->cascadeOnDelete();
            $table->string('key', 120);
            $table->string('title');
            $table->string('scope_type', 80);
            $table->string('status', 60)->default('planned');
            $table->string('generation_stage', 100)->nullable();
            $table->unsignedTinyInteger('generation_progress')->default(0);
            $table->unsignedInteger('target_items_min')->default(0);
            $table->unsignedInteger('target_items_max')->default(0);
            $table->unsignedInteger('actual_items_count')->default(0);
            $table->json('totals')->nullable();
            $table->json('quality_summary')->nullable();
            $table->json('assumptions')->nullable();
            $table->json('source_refs')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'key']);
            $table->index(['session_id', 'status']);
            $table->index(['session_id', 'sort_order']);
            $table->index('scope_type');
        });

        Schema::create('estimate_generation_package_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained('estimate_generation_packages')->cascadeOnDelete();
            $table->string('key', 180);
            $table->string('parent_key', 180)->nullable();
            $table->unsignedSmallInteger('level')->default(0);
            $table->string('item_type', 60)->default('work');
            $table->text('name');
            $table->string('unit', 50)->nullable();
            $table->decimal('quantity', 18, 6)->nullable();
            $table->json('quantity_basis')->nullable();
            $table->string('price_source', 80)->nullable();
            $table->string('normative_status', 80)->nullable();
            $table->decimal('normative_confidence', 5, 4)->nullable();
            $table->decimal('unit_price', 18, 6)->nullable();
            $table->decimal('direct_cost', 18, 2)->default(0);
            $table->decimal('overhead_cost', 18, 2)->default(0);
            $table->decimal('profit_cost', 18, 2)->default(0);
            $table->decimal('total_cost', 18, 2)->default(0);
            $table->json('resources')->nullable();
            $table->json('flags')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['package_id', 'key']);
            $table->index(['package_id', 'sort_order']);
            $table->index(['package_id', 'item_type']);
            $table->index('parent_key');
        });

        Schema::create('estimate_generation_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('estimate_generation_sessions')->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('estimate_generation_packages')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 120);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_generation_audit_events');
        Schema::dropIfExists('estimate_generation_package_items');
        Schema::dropIfExists('estimate_generation_packages');
    }
};
