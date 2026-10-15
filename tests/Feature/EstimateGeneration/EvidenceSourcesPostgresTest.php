<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetEvidenceSources;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EvidenceSourcesPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_real_authorized_reader_resolves_current_sources_without_urls_or_spending_and_rejects_stale_foreign_and_revoked_access(): void
    {
        config(['cache.default' => 'array']);
        Http::fake([]);
        Http::preventStrayRequests();
        Queue::fake();
        $rights = AssistantRealAuthorizationFixture::create();
        $rights->memberRole->update(['module_permissions' => ['ai-estimates' => ['estimate_generation.view']]]);
        $actor = $rights->member;
        $actor->organizations()->updateExistingPivot($rights->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->for($rights->organization)->create(['is_archived' => false]);
        AuthorizationContext::getProjectContext((int) $project->id, (int) $rights->organization->id);
        $this->actingAs($actor);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $rights->organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => [], 'draft_payload' => null]);
        $scope = [(int) $rights->organization->id, (int) $project->id, (int) $session->id];
        $document = EstimateGenerationDocument::query()->create(['session_id' => $session->id, 'organization_id' => $scope[0],
            'project_id' => $scope[1], 'user_id' => $actor->id, 'filename' => 'plan.pdf', 'mime_type' => 'application/pdf',
            'storage_path' => 'org-'.$scope[0].'/source-fixture.pdf', 'file_size_bytes' => 100,
            'checksum_sha256' => str_repeat('d', 64), 'status' => 'ready', 'page_count' => 3]);
        $version = DocumentSourceVersion::fromDocument($document);
        $repository = app(EvidenceRepository::class);
        $node = $repository->insertOrGet(new EvidenceData(...[...$scope, EvidenceType::SourceFact, EvidenceSourceType::Document,
            'document:'.$document->id, $version, ['document_id' => (int) $document->id, 'page' => 2,
                'bbox' => [0.1, 0.2, 0.4, 0.6]],
            ['fact_key' => 'area', 'fact_value' => '12.3'], 0.0, 'test', 'sha256:'.str_repeat('a', 64)]));
        $reference = 'evidence:'.$node->id;
        $reader = app(GetEvidenceSources::class);
        $before = $session->fresh()->getRawOriginal();
        $result = $reader->handle($actor, $scope[1], $scope[2], [$reference, 'source-ref:unresolved']);
        self::assertSame('available', $result['sources'][0]['status']);
        self::assertSame((int) $document->id, $result['sources'][0]['target']['artifact_id']);
        self::assertSame(2, $result['sources'][0]['target']['page']);
        self::assertNull($result['sources'][0]['target']['coordinate_space']); // Legacy bbox has no declared unit contract.
        self::assertEqualsWithDelta(['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.4], $result['sources'][0]['target']['region'], 0.000001);
        self::assertSame('unavailable', $result['sources'][1]['status']);
        self::assertSame('0', $result['cost_units']);
        $request = \Illuminate\Http\Request::create('/evidence-sources', 'GET', ['references' => [$reference]]);
        $request->setUserResolver(static fn () => $actor);
        $response = app(\App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationEvidenceController::class)
            ->show($request, $project, $session, $reader);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->getData(true)['success']);
        self::assertSame((int) $document->id, $response->getData(true)['data']['sources'][0]['target']['artifact_id']);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringNotContainsString('storage_path', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('"12.3"', json_encode($result, JSON_THROW_ON_ERROR));
        $inputNode = $repository->insertOrGet(new EvidenceData(...[...$scope, EvidenceType::SourceFact, EvidenceSourceType::UserInput,
            'input:'.$session->id, $version, ['source_key' => 'source:'.str_repeat('a', 64), 'native_reference' => 'input_payload.description'],
            ['fact_key' => 'evaluation_choice', 'fact_value' => str_repeat('a', 64)], 0.0, 'user_input_normalizer', 'sha256:'.str_repeat('a', 64)]));
        $inputSource = $reader->handle($actor, $scope[1], $scope[2], ['evidence:'.$inputNode->id])['sources'][0];
        self::assertSame('user_input', $inputSource['status']);
        self::assertNull($inputSource['target']);
        foreach ([2, 3] as $page) {
            $regionNode = $repository->insertOrGet(new EvidenceData(...[...$scope, EvidenceType::SourceFact, EvidenceSourceType::PageRegion,
                'document:'.$document->id.'/page:2/region:'.str_repeat('a', 64), $version,
                ['document_id' => (int) $document->id, 'page' => $page, 'bbox' => [0.1, 0.2, 0.4, 0.6]],
                ['fact_key' => 'area', 'fact_value' => '12.3'], 0.0, 'test', 'sha256:'.str_repeat('a', 64)]));
            $regionSource = $reader->handle($actor, $scope[1], $scope[2], ['evidence:'.$regionNode->id])['sources'][0];
            self::assertSame($page === 2 ? 'available' : 'unavailable', $regionSource['status']);
            self::assertSame($page === 2 ? 2 : null, $regionSource['target']['page'] ?? null);
        }
        $otherSession = $session->replicate();
        $otherSession->save();
        $otherDocument = $document->replicate();
        $otherDocument->session_id = $otherSession->id;
        $otherDocument->filename = 'foreign-plan.pdf';
        $otherDocument->save();
        $foreignNode = $repository->insertOrGet(new EvidenceData(...[...$scope, EvidenceType::SourceFact, EvidenceSourceType::Document,
            'document:'.$otherDocument->id, $version, ['document_id' => (int) $otherDocument->id, 'page' => 2],
            ['fact_key' => 'area', 'fact_value' => '12.3'], 0.0, 'test', 'sha256:'.str_repeat('a', 64)]));
        $foreignSource = $reader->handle($actor, $scope[1], $scope[2], ['evidence:'.$foreignNode->id])['sources'][0];
        self::assertSame('unavailable', $foreignSource['status']);
        self::assertStringNotContainsString('foreign-plan.pdf', json_encode($foreignSource, JSON_THROW_ON_ERROR));
        $document->update(['checksum_sha256' => str_repeat('e', 64)]);
        self::assertNull($reader->handle($actor, $scope[1], $scope[2], [$reference])['sources'][0]['target']);
        $document->update(['checksum_sha256' => str_repeat('d', 64), 'status' => 'ignored']);
        self::assertNull($reader->handle($actor, $scope[1], $scope[2], [$reference])['sources'][0]['target']);
        $document->update(['status' => 'ready']);
        $repository->invalidate(...[...$scope, [$node->id], 'source_replaced']);
        self::assertSame('unavailable', $reader->handle($actor, $scope[1], $scope[2], [$reference])['sources'][0]['status']);
        try {
            $reader->handle($actor, $scope[1] + 10000, $scope[2], [$reference]);
            self::fail('A foreign project accessed a source.');
        } catch (ModelNotFoundException) {
            self::addToAssertionCount(1);
        }
        $rights->memberRole->update(['module_permissions' => ['ai-estimates' => []]]);
        try {
            $reader->handle($actor, $scope[1], $scope[2], [$reference]);
            self::fail('A revoked cached permission accessed a source.');
        } catch (AuthorizationException) {
            self::addToAssertionCount(1);
        }
        self::assertSame($before, $session->fresh()->getRawOriginal());
        foreach (['estimate_generation_credit_operations', 'estimate_generation_ai_usage', 'estimate_generation_credit_attempts'] as $table) {
            self::assertSame(0, DB::table($table)->where('session_id', $scope[2])->count());
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
