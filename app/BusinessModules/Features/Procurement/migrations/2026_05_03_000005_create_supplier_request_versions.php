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
        Schema::create('supplier_request_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('supplier_request_id')->constrained('supplier_requests')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('request_snapshot')->default('{}');
            $table->json('line_snapshot')->default('[]');
            $table->json('supplier_snapshot')->default('{}');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['supplier_request_id', 'version_number'], 'supplier_request_versions_request_version_unique');
            $table->index(['organization_id', 'supplier_request_id']);
        });

        Schema::table('supplier_proposals', function (Blueprint $table): void {
            if (!Schema::hasColumn('supplier_proposals', 'supplier_request_version_id')) {
                $table->foreignId('supplier_request_version_id')
                    ->nullable()
                    ->after('supplier_request_id')
                    ->constrained('supplier_request_versions')
                    ->nullOnDelete();
            }
        });

        $this->backfillSentVersions();
    }

    public function down(): void
    {
        Schema::table('supplier_proposals', function (Blueprint $table): void {
            if (Schema::hasColumn('supplier_proposals', 'supplier_request_version_id')) {
                $table->dropConstrainedForeignId('supplier_request_version_id');
            }
        });

        Schema::dropIfExists('supplier_request_versions');
    }

    private function backfillSentVersions(): void
    {
        DB::table('supplier_requests')
            ->whereNotNull('sent_at')
            ->orderBy('id')
            ->chunkById(100, function ($requests): void {
                foreach ($requests as $request) {
                    $lines = DB::table('supplier_request_lines')
                        ->where('supplier_request_id', $request->id)
                        ->orderBy('id')
                        ->get()
                        ->map(static fn ($line): array => [
                            'id' => $line->id,
                            'purchase_request_line_id' => $line->purchase_request_line_id,
                            'material_id' => $line->material_id,
                            'name' => $line->name,
                            'quantity' => (float) $line->quantity,
                            'unit' => $line->unit,
                            'specification' => $line->specification,
                            'metadata' => $line->metadata ? json_decode($line->metadata, true) : null,
                        ])
                        ->values()
                        ->all();

                    $versionId = DB::table('supplier_request_versions')->insertGetId([
                        'organization_id' => $request->organization_id,
                        'supplier_request_id' => $request->id,
                        'version_number' => 1,
                        'request_snapshot' => json_encode([
                            'id' => $request->id,
                            'request_number' => $request->request_number,
                            'status' => $request->status,
                            'sent_at' => $request->sent_at,
                            'comment' => $request->comment,
                            'metadata' => $request->metadata ? json_decode($request->metadata, true) : null,
                            'purchase_request_id' => $request->purchase_request_id,
                        ], JSON_THROW_ON_ERROR),
                        'line_snapshot' => json_encode($lines, JSON_THROW_ON_ERROR),
                        'supplier_snapshot' => $request->supplier_snapshot ?: '{}',
                        'sent_by' => null,
                        'sent_at' => $request->sent_at,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('supplier_proposals')
                        ->where('organization_id', $request->organization_id)
                        ->where('supplier_request_id', $request->id)
                        ->whereNull('supplier_request_version_id')
                        ->update([
                            'supplier_request_version_id' => $versionId,
                            'updated_at' => now(),
                        ]);
                }
            });
    }
};
