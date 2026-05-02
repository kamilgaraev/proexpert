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
        Schema::create('supplier_proposal_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('supplier_proposal_id')->constrained('supplier_proposals')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->jsonb('commercial_snapshot')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('attachment_snapshot')->default(DB::raw("'{}'::jsonb"));
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['supplier_proposal_id', 'version_number'], 'supplier_proposal_versions_number_unique');
            $table->index(['organization_id', 'supplier_proposal_id']);
        });

        DB::table('supplier_proposals')
            ->orderBy('id')
            ->chunkById(500, function ($proposals): void {
                $now = now();

                foreach ($proposals as $proposal) {
                    DB::table('supplier_proposal_versions')->insert([
                        'organization_id' => $proposal->organization_id,
                        'supplier_proposal_id' => $proposal->id,
                        'version_number' => 1,
                        'commercial_snapshot' => json_encode([
                            'proposal_number' => $proposal->proposal_number,
                            'proposal_date' => $proposal->proposal_date,
                            'subtotal_amount' => (float) ($proposal->subtotal_amount ?? 0),
                            'delivery_amount' => (float) ($proposal->delivery_amount ?? 0),
                            'vat_amount' => (float) ($proposal->vat_amount ?? 0),
                            'total_amount' => (float) ($proposal->total_amount ?? 0),
                            'currency' => $proposal->currency ?? 'RUB',
                            'vat_mode' => 'included',
                            'vat_rate' => null,
                            'valid_until' => $proposal->valid_until,
                            'delivery_due_date' => null,
                            'lead_time_days' => null,
                            'payment_terms' => $proposal->payment_terms ?? null,
                            'delivery_terms' => $proposal->delivery_terms ?? null,
                            'warranty_terms' => null,
                            'lines' => $this->proposalLines((int) $proposal->id),
                        ], JSON_THROW_ON_ERROR),
                        'attachment_snapshot' => json_encode([
                            'intake_attachment_ids' => [],
                        ], JSON_THROW_ON_ERROR),
                        'created_by' => null,
                        'created_at' => $proposal->created_at ?? $now,
                    ]);
                }
            });

        Schema::table('supplier_proposal_decisions', function (Blueprint $table): void {
            if (!Schema::hasColumn('supplier_proposal_decisions', 'winning_supplier_proposal_version_id')) {
                $table->foreignId('winning_supplier_proposal_version_id')
                    ->nullable()
                    ->after('winning_supplier_proposal_id')
                    ->constrained('supplier_proposal_versions')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('supplier_proposal_decisions', 'cheapest_supplier_proposal_version_id')) {
                $table->foreignId('cheapest_supplier_proposal_version_id')
                    ->nullable()
                    ->after('cheapest_supplier_proposal_id')
                    ->constrained('supplier_proposal_versions')
                    ->nullOnDelete();
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('purchase_orders', 'accepted_supplier_proposal_version_id')) {
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

    private function proposalLines(int $proposalId): array
    {
        return DB::table('supplier_proposal_lines')
            ->where('supplier_proposal_id', $proposalId)
            ->orderBy('id')
            ->get()
            ->map(static fn ($line): array => [
                'id' => $line->id,
                'supplier_request_line_id' => $line->supplier_request_line_id,
                'material_id' => $line->material_id,
                'name' => $line->name,
                'quantity' => (float) $line->quantity,
                'unit' => $line->unit,
                'unit_price' => (float) $line->unit_price,
                'total_amount' => (float) $line->total_amount,
                'comment' => $line->comment,
            ])
            ->values()
            ->all();
    }
};
