<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $emptyJsonObject = DB::connection()->getDriverName() === 'pgsql'
            ? DB::raw("'{}'::jsonb")
            : '{}';

        Schema::create('supplier_proposal_versions', function (Blueprint $table) use ($emptyJsonObject): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('supplier_proposal_id')->constrained('supplier_proposals')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->jsonb('commercial_snapshot')->default($emptyJsonObject);
            $table->jsonb('attachment_snapshot')->default($emptyJsonObject);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['supplier_proposal_id', 'version_number'], 'supplier_proposal_versions_number_unique');
            $table->index(['organization_id', 'supplier_proposal_id']);
        });

        Schema::table('supplier_proposal_decisions', function (Blueprint $table): void {
            if (! Schema::hasColumn('supplier_proposal_decisions', 'winning_supplier_proposal_version_id')) {
                $table->foreignId('winning_supplier_proposal_version_id')
                    ->nullable()
                    ->after('winning_supplier_proposal_id')
                    ->constrained('supplier_proposal_versions')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('supplier_proposal_decisions', 'cheapest_supplier_proposal_version_id')) {
                $table->foreignId('cheapest_supplier_proposal_version_id')
                    ->nullable()
                    ->after('cheapest_supplier_proposal_id')
                    ->constrained('supplier_proposal_versions')
                    ->nullOnDelete();
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_orders', 'accepted_supplier_proposal_version_id')) {
                $table->foreignId('accepted_supplier_proposal_version_id')
                    ->nullable()
                    ->after('accepted_supplier_proposal_id')
                    ->constrained('supplier_proposal_versions')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('purchase_orders', 'accepted_supplier_proposal_version_id')) {
                $table->dropColumn('accepted_supplier_proposal_version_id');
            }
        });

        Schema::table('supplier_proposal_decisions', function (Blueprint $table): void {
            foreach (['cheapest_supplier_proposal_version_id', 'winning_supplier_proposal_version_id'] as $column) {
                if (Schema::hasColumn('supplier_proposal_decisions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('supplier_proposal_versions');
    }
};
