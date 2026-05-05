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
        $emptyJsonArray = DB::connection()->getDriverName() === 'pgsql'
            ? DB::raw("'[]'::jsonb")
            : '[]';

        Schema::create('supplier_proposal_intakes', function (Blueprint $table) use ($emptyJsonArray): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('supplier_proposal_id')->constrained('supplier_proposals')->cascadeOnDelete();
            $table->foreignId('supplier_party_id')->nullable()->constrained('supplier_parties')->nullOnDelete();
            $table->string('source', 64);
            $table->timestampTz('received_at');
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('external_reference')->nullable();
            $table->text('comment')->nullable();
            $table->jsonb('attachment_ids')->default($emptyJsonArray);
            $table->timestamps();

            $table->unique('supplier_proposal_id', 'supplier_proposal_intakes_proposal_unique');
            $table->index(['organization_id', 'source']);
            $table->index(['supplier_party_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_proposal_intakes');
    }
};
