<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE FUNCTION eg_geometry_confirmation_semantic_valid_v2(payload jsonb) RETURNS boolean LANGUAGE sql IMMUTABLE STRICT AS $$
  SELECT jsonb_typeof(payload) = 'object'
    AND (SELECT count(*) FROM jsonb_object_keys(payload)) = 6
    AND payload ?& ARRAY['schema_version','source_fingerprint','geometry_payload_sha256','scale_evidence','elements','source_confirmation_context']
    AND jsonb_typeof(payload->'source_confirmation_context') = 'object'
    AND ((payload->'source_confirmation_context') - ARRAY['document_id','page_id','source_version']) = '{}'::jsonb
    AND payload->'source_confirmation_context' ?& ARRAY['document_id','page_id','source_version']
    AND jsonb_typeof(payload->'source_confirmation_context'->'document_id') = 'number'
    AND jsonb_typeof(payload->'source_confirmation_context'->'page_id') = 'number'
    AND payload->'source_confirmation_context'->>'document_id' ~ '^[1-9][0-9]*$'
    AND payload->'source_confirmation_context'->>'page_id' ~ '^[1-9][0-9]*$'
    AND payload->'source_confirmation_context'->>'source_version' ~ '^sha256:[a-f0-9]{64}$'
    AND eg_geometry_confirmation_semantic_valid_v1(payload - 'source_confirmation_context');
$$;
ALTER TABLE estimate_generation_geometry_confirmations
  DROP CONSTRAINT eg_geometry_confirmation_payload_ck,
  ADD CONSTRAINT eg_geometry_confirmation_payload_ck CHECK (eg_geometry_confirmation_semantic_valid_v2(semantic_payload)) NOT VALID;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE estimate_generation_geometry_confirmations
  DROP CONSTRAINT eg_geometry_confirmation_payload_ck,
  ADD CONSTRAINT eg_geometry_confirmation_payload_ck CHECK (eg_geometry_confirmation_semantic_valid_v1(semantic_payload)) NOT VALID;
DROP FUNCTION eg_geometry_confirmation_semantic_valid_v2(jsonb);
SQL);
    }
};
