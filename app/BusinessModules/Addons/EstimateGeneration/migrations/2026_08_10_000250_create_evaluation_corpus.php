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
        Schema::create('estimate_generation_evaluation_examples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('source_version', 71);
            $table->jsonb('expected_facts');
            $table->jsonb('expected_decisions');
            $table->jsonb('expected_quantities');
            $table->jsonb('expected_estimate_rows');
            $table->jsonb('contract_versions');
            $table->string('trust_status', 16);
            $table->string('dataset_split', 16);
            $table->string('content_fingerprint', 71);
            $table->string('review_actor_type', 64)->nullable();
            $table->unsignedBigInteger('review_actor_id')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'source_version'], 'eg_evaluation_example_source_uq');
            $table->index(
                ['organization_id', 'trust_status', 'dataset_split'],
                'eg_evaluation_release_corpus_idx',
            );
        });

        DB::statement(<<<'SQL'
ALTER TABLE estimate_generation_evaluation_examples
ADD CONSTRAINT eg_evaluation_example_contract_ck CHECK (
    source_version ~ '^sha256:[a-f0-9]{64}$'
    AND content_fingerprint ~ '^sha256:[a-f0-9]{64}$'
    AND trust_status IN ('candidate', 'reviewed', 'rejected')
    AND dataset_split IN ('development', 'test')
    AND (
        (trust_status = 'candidate'
            AND review_actor_type IS NULL
            AND review_actor_id IS NULL
            AND review_reason IS NULL
            AND reviewed_at IS NULL)
        OR
        (trust_status IN ('reviewed', 'rejected')
            AND review_actor_type ~ '^[a-z][a-z0-9_]{1,63}$'
            AND review_actor_id IS NOT NULL
            AND review_reason IS NOT NULL
            AND BTRIM(review_reason) <> ''
            AND reviewed_at IS NOT NULL)
    )
)
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Evaluation corpus migration is irreversible.');
    }
};
