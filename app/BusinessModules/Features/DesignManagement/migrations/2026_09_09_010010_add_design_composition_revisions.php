<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\BusinessModules\Features\DesignManagement\Services\LegacyDesignCompositionBackfillService;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_packages', function (Blueprint $table): void {
            $table->text('composition_status')->nullable()->after('status');
            $table->unsignedBigInteger('composition_revision_id')->nullable()->after('composition_status');
            $table->index(['project_id', 'composition_status']);
        });
        Schema::create('design_composition_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('design_packages')->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->text('status')->default('draft');
            $table->jsonb('composition');
            $table->text('fingerprint');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->text('needs_review_reason')->nullable();
            $table->timestampsTz();
            $table->unique(['package_id', 'revision_number']);
            $table->index(['package_id', 'status']);
        });
        Schema::create('design_composition_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('design_packages')->cascadeOnDelete();
            $table->foreignId('revision_id')->constrained('design_composition_revisions')->cascadeOnDelete();
            $table->text('item_key');
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['revision_id', 'item_key']);
        });
        app(LegacyDesignCompositionBackfillService::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('design_composition_exclusions');
        Schema::dropIfExists('design_composition_revisions');
        Schema::table('design_packages', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'composition_status']);
            $table->dropColumn(['composition_status', 'composition_revision_id']);
        });
    }
};
