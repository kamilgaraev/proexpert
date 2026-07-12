<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        if (! DB::table('estimate_normative_retrieval_rollouts')->where('schema_version', 'normative-retrieval-v1')->where('status', 'complete')->exists()) {
            throw new RuntimeException('Normative retrieval backfill is incomplete.');
        }
        DB::statement('ALTER TABLE estimate_norms ADD CONSTRAINT estimate_norms_validity_ck CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from) NOT VALID');
        DB::statement('ALTER TABLE estimate_norm_semantic_scores ADD CONSTRAINT estimate_norm_semantic_score_ck CHECK (score >= 0 AND score <= 1) NOT VALID');
        DB::statement('ALTER TABLE estimate_norms VALIDATE CONSTRAINT estimate_norms_validity_ck');
        DB::statement('ALTER TABLE estimate_norm_semantic_scores VALIDATE CONSTRAINT estimate_norm_semantic_score_ck');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE estimate_norms DROP CONSTRAINT IF EXISTS estimate_norms_validity_ck');
        DB::statement('ALTER TABLE estimate_norm_semantic_scores DROP CONSTRAINT IF EXISTS estimate_norm_semantic_score_ck');
    }
};
