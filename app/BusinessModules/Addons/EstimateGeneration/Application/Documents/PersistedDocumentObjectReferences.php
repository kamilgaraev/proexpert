<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use Illuminate\Support\Facades\DB;

/** Reference checks cover all organizations and archived records; document text is not a locator. */
final class PersistedDocumentObjectReferences
{
    public function contains(string $path): bool
    {
        if (DB::table('estimate_generation_documents')->where('storage_path', $path)->exists()) {
            return true;
        }
        $units = DB::table('estimate_generation_processing_units');
        foreach (['artifact_path', 'visual_artifact_path', 'geometry_artifact_path', 'text_spans_artifact_path', 'vector_artifact_path',
            'representation->visual_artifact_path', 'document_representation->visual_artifact_path'] as $field) {
            $units->orWhere('locator->'.$field, $path);
        }
        if ($units->exists()) {
            return true;
        }

        $pages = DB::table('estimate_generation_document_pages')->where('raw_payload_path', $path);
        foreach (['source->artifact_path', 'source->visual_artifact_path', 'representation->visual_artifact_path',
            'document_representation->artifact_path', 'document_representation->visual_artifact_path'] as $field) {
            $pages->orWhere('normalized_payload->'.$field, $path);
        }

        return $pages->exists();
    }
}
