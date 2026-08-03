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
ALTER TABLE estimate_generation_processing_units
    DROP CONSTRAINT IF EXISTS eg_units_locator_provenance_ck;

UPDATE estimate_generation_processing_units
SET locator = locator || jsonb_build_object(
    'source_kind', CASE unit_type
        WHEN 'pdf_page' THEN 'pdf'
        WHEN 'spreadsheet_sheet' THEN 'spreadsheet'
        WHEN 'raster_image' THEN 'image'
        WHEN 'sketch' THEN 'image'
        WHEN 'cad_drawing' THEN 'cad'
        WHEN 'text_page' THEN 'text'
    END,
    'source_version', source_version,
    'coordinate_space', CASE unit_type
        WHEN 'pdf_page' THEN 'pdf_page_pixels'
        WHEN 'spreadsheet_sheet' THEN 'spreadsheet_cells'
        WHEN 'raster_image' THEN 'image_pixels'
        WHEN 'sketch' THEN 'image_pixels'
        WHEN 'cad_drawing' THEN 'cad_model'
        WHEN 'text_page' THEN 'text_offsets'
    END
)
WHERE NOT (locator ?& ARRAY['source_kind', 'source_version', 'coordinate_space']);

ALTER TABLE estimate_generation_processing_units
    ADD CONSTRAINT eg_units_locator_provenance_ck
    CHECK (
        locator ?& ARRAY['source_kind', 'source_version', 'coordinate_space', 'artifact_path', 'artifact_sha256', 'artifact_version_id']
        AND jsonb_typeof(locator->'source_kind') = 'string'
        AND jsonb_typeof(locator->'source_version') = 'string'
        AND jsonb_typeof(locator->'coordinate_space') = 'string'
        AND jsonb_typeof(locator->'artifact_path') = 'string'
        AND jsonb_typeof(locator->'artifact_sha256') = 'string'
        AND jsonb_typeof(locator->'artifact_version_id') = 'string'
        AND (
            (unit_type = 'pdf_page' AND locator->>'source_kind' = 'pdf' AND locator->>'coordinate_space' = 'pdf_page_pixels')
            OR (unit_type = 'spreadsheet_sheet' AND locator->>'source_kind' = 'spreadsheet' AND locator->>'coordinate_space' = 'spreadsheet_cells')
            OR (unit_type IN ('raster_image', 'sketch') AND locator->>'source_kind' = 'image' AND locator->>'coordinate_space' = 'image_pixels')
            OR (unit_type = 'cad_drawing' AND locator->>'source_kind' = 'cad' AND locator->>'coordinate_space' = 'cad_model')
            OR (unit_type = 'text_page' AND locator->>'source_kind' = 'text' AND locator->>'coordinate_space' = 'text_offsets')
        )
        AND locator->>'source_version' = source_version
        AND char_length(locator->>'artifact_path') BETWEEN 1 AND 2048
        AND locator->>'artifact_sha256' ~ '^sha256:[a-f0-9]{64}$'
        AND locator->>'artifact_version_id' ~ '^[!-~]{1,1024}$'
    ) NOT VALID;
SQL);
    }

    public function down(): void
    {
    }
};
