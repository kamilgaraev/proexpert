<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EloquentDocumentUnitAggregateReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\OriginalDocumentArtifactLocator;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class DocumentAggregateVerificationPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    use \Tests\Support\EstimateGeneration\SeedsEstimatorSettings;

    public function test_completed_processing_is_not_a_calibrated_accuracy_score(): void
    {
        $this->seedEstimatorSettings();
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create();
        $source = 'sha256:'.hash('sha256', 'aggregate fixture');
        $attempt = (string) Str::uuid();
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id,
            'project_id' => $project->id, 'user_id' => $actor->id, 'status' => 'processing_documents', 'state_version' => 0, 'input_payload' => []]);
        $document = EstimateGenerationDocument::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'session_id' => $session->id, 'user_id' => $actor->id, 'filename' => 'fixture.pdf', 'mime_type' => 'application/pdf',
            'storage_path' => 'org-'.$organization->id.'/estimate-generation/sessions/'.$session->id.'/documents/'.Str::uuid().'.pdf',
            'file_size_bytes' => 17, 'checksum_sha256' => substr($source, 7), 'source_version' => $source,
            'status' => 'processing', 'meta' => ['processing_attempt_id' => $attempt]]);
        $unit = EstimateGenerationProcessingUnit::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'session_id' => $session->id, 'document_id' => $document->id, 'unit_type' => 'pdf_page', 'unit_index' => 1,
            'source_version' => $source, 'status' => 'completed', 'output_version' => $source, 'output_count' => 1, 'completed_at' => now(),
            'locator' => OriginalDocumentArtifactLocator::forUnit($document, DocumentUnitType::PdfPage, 1, $source, ['page' => 1]),
            'metadata' => ['processing_attempt_id' => $attempt]]);
        EstimateGenerationDocumentPage::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'session_id' => $session->id, 'document_id' => $document->id, 'processing_unit_id' => $unit->id, 'page_number' => 1,
            'source_version' => $source, 'output_version' => $source, 'status' => 'ready', 'text' => 'План помещения',
            'normalized_payload' => ['schema_version' => 3], 'quality_flags' => [], 'confidence' => null]);
        app(EloquentDocumentUnitAggregateReconciler::class)->reconcile($document->id, $source);
        $result = $document->fresh();
        self::assertSame('ready', $result->status);
        self::assertSame(100, $result->progress_percent);
        self::assertNull($result->quality_score);
        self::assertNull($result->quality_level);
    }
}
