<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\AssistantDesignIfcElementPreviewFormatter;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\DesignAdditionalRagSource;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\Models\File;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantDesignIfcRetrievalTest extends TestCase
{
    use RefreshDatabase;

    public function test_large_ifc_model_yields_bounded_chunks_and_skips_oversized_properties(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $project = $this->project((int) $fixture->organization->id);
        $version = $this->version((int) $fixture->organization->id, $project, 'large-model.ifc');
        $largeValue = str_repeat('private-property-secret-', 3000);
        $timestamp = now();
        $rows = [];

        for ($index = 1; $index <= 65; $index++) {
            $rows[] = [
                'organization_id' => $fixture->organization->id,
                'project_id' => $project->id,
                'version_id' => $version->id,
                'express_id' => $index,
                'global_id' => 'global-'.$index,
                'category' => 'IfcWall',
                'name' => 'Элемент '.$index.str_repeat('длинное имя ', 30),
                'properties' => json_encode(['oversized' => $largeValue, 'materials' => ['Бетон']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'classifications' => '{}',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }
        $rows[] = [
            'organization_id' => $fixture->organization->id,
            'project_id' => $project->id,
            'version_id' => $version->id,
            'express_id' => 9999,
            'global_id' => 'global-small',
            'category' => 'IfcSlab',
            'name' => 'Плита',
            'properties' => json_encode([
                'materials' => ['Бетон', 'Сталь'],
                'quantities' => ['Qto_Slab' => ['NetVolume' => 12.5]],
                'classified_secret' => 'private-classification',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'classifications' => '{}',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
        DB::table('design_ifc_model_elements')->insert($rows);
        $smallElementId = (int) DB::table('design_ifc_model_elements')->where('version_id', $version->id)->where('express_id', 9999)->value('id');

        DB::enableQueryLog();
        try {
            $chunks = iterator_to_array((function () use ($fixture): iterable {
                yield from (new DesignAdditionalRagSource)->collectForOrganization((int) $fixture->organization->id);
            })());
            $queries = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $ifcChunks = array_values(array_filter($chunks, static fn ($chunk): bool => $chunk->entityType === 'design_ifc_model_element'));
        self::assertCount(66, $ifcChunks);
        self::assertStringContainsString('octet_length', implode("\n", $queries));

        foreach ($ifcChunks as $chunk) {
            self::assertLessThanOrEqual(AssistantDesignIfcElementPreviewFormatter::MAX_CONTENT_CHARS, mb_strlen($chunk->content, 'UTF-8'));
            self::assertStringNotContainsString('private-property-secret', $chunk->content);
            self::assertStringNotContainsString('private-classification', $chunk->content);
        }

        $smallChunk = array_values(array_filter($ifcChunks, static fn ($chunk): bool => (int) $chunk->entityId === $smallElementId))[0];
        self::assertStringContainsString('Материалы из IFC: Бетон; Сталь', $smallChunk->content);
        self::assertStringContainsString('NetVolume = 12.5', $smallChunk->content);
    }

    public function test_coverage_does_not_report_ready_when_ifc_rows_or_retrievable_chunks_are_missing(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $project = $this->project((int) $fixture->organization->id);
        $emptyVersion = $this->version((int) $fixture->organization->id, $project, 'empty.ifc');
        $this->attachFile($emptyVersion, $fixture->owner);
        DesignModelDerivative::withoutEvents(fn (): DesignModelDerivative => DesignModelDerivative::query()->create([
            'organization_id' => $fixture->organization->id,
            'project_id' => $project->id,
            'version_id' => $emptyVersion->id,
            'status' => 'ready',
            'metadata' => ['indexed_element_count' => 0],
        ]));

        $missingRowsVersion = $this->version((int) $fixture->organization->id, $project, 'missing-rows.ifc');
        $this->attachFile($missingRowsVersion, $fixture->owner);
        DesignModelDerivative::withoutEvents(fn (): DesignModelDerivative => DesignModelDerivative::query()->create([
            'organization_id' => $fixture->organization->id,
            'project_id' => $project->id,
            'version_id' => $missingRowsVersion->id,
            'status' => 'ready',
            'metadata' => ['indexed_element_count' => 500],
        ]));

        $version = $this->version((int) $fixture->organization->id, $project, 'model.ifc');
        $this->attachFile($version, $fixture->owner);
        $derivative = DesignModelDerivative::withoutEvents(fn (): DesignModelDerivative => DesignModelDerivative::query()->create([
            'organization_id' => $fixture->organization->id,
            'project_id' => $project->id,
            'version_id' => $version->id,
            'status' => 'ready',
            'metadata' => ['indexed_element_count' => 4],
        ]));
        $elements = [];
        for ($index = 1; $index <= 3; $index++) {
            $elements[] = $this->element((int) $fixture->organization->id, $project, $version, $index);
        }

        $coverageService = app(AssistantDocumentCoverageService::class);
        $emptyCoverage = $coverageService->coverage((int) $fixture->organization->id, $fixture->owner)['document_coverage'];
        self::assertSame(3, $emptyCoverage['total']);
        self::assertSame(0, $emptyCoverage['ready']);
        self::assertSame(1, $emptyCoverage['empty']);
        self::assertSame(2, $emptyCoverage['pending']);
        self::assertSame(0, $emptyCoverage['processed_units']);

        $embedding = $this->useTestEmbeddingProvider();
        $indexer = new RagIndexer($embedding, app(RagSourceRegistry::class), Mockery::mock(UsageTracker::class)->shouldIgnoreMissing());
        $initialCoverage = $coverageService->coverage((int) $fixture->organization->id, $fixture->owner)['document_coverage'];
        self::assertSame(0, $initialCoverage['ready']);
        self::assertSame(2, $initialCoverage['pending']);
        self::assertSame(0, $initialCoverage['processed_units']);

        self::assertSame(1, $indexer->indexEntity((int) $fixture->organization->id, 'design_additional', 'design_ifc_model_element', $elements[0]->id));
        self::assertSame(1, $indexer->indexEntity((int) $fixture->organization->id, 'design_additional', 'design_ifc_model_element', $elements[1]->id));
        $partialCoverage = $coverageService->coverage((int) $fixture->organization->id, $fixture->owner)['document_coverage'];
        self::assertSame(0, $partialCoverage['ready']);
        self::assertSame(2, $partialCoverage['pending']);
        self::assertSame(2, $partialCoverage['processed_units']);

        self::assertSame(1, $indexer->indexEntity((int) $fixture->organization->id, 'design_additional', 'design_ifc_model_element', $elements[2]->id));
        $mismatchedCoverage = $coverageService->coverage((int) $fixture->organization->id, $fixture->owner)['document_coverage'];
        self::assertSame(0, $mismatchedCoverage['ready']);
        self::assertSame(2, $mismatchedCoverage['pending']);
        self::assertSame(3, $mismatchedCoverage['processed_units']);

        $derivative->forceFill(['metadata' => ['indexed_element_count' => 3]])->save();
        $readyCoverage = $coverageService->coverage((int) $fixture->organization->id, $fixture->owner)['document_coverage'];
        self::assertSame(1, $readyCoverage['ready']);
        self::assertSame(3, $readyCoverage['processed_units']);
        self::assertSame(3, $embedding->calls);
        self::assertLessThanOrEqual(AssistantDesignIfcElementPreviewFormatter::MAX_CONTENT_CHARS, $embedding->maxTextChars);

        $source = RagSource::query()->where('organization_id', $fixture->organization->id)
            ->where('entity_type', 'design_ifc_model_element')->where('entity_id', (string) $elements[0]->id)->firstOrFail();
        DB::table('ai_rag_chunks')->where('source_id', $source->id)->update(['embedding_model' => 'obsolete-model']);
        $incompatibleCoverage = $coverageService->coverage((int) $fixture->organization->id, $fixture->owner)['document_coverage'];
        self::assertSame(0, $incompatibleCoverage['ready']);
        self::assertSame(2, $incompatibleCoverage['pending']);
        self::assertSame(2, $incompatibleCoverage['processed_units']);
    }

    public function test_coverage_uses_the_actors_project_and_organization_scope(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $fixture->memberRole->update(['module_permissions' => [
            'ai-assistant' => ['ai_assistant.chat'],
            'project-management' => ['projects.view'],
            'design-management' => ['design-management.view', 'design-management.models.view'],
        ]]);
        $visibleProject = $this->project((int) $fixture->organization->id);
        $hiddenProject = $this->project((int) $fixture->organization->id);
        $foreignProject = $this->project((int) $fixture->foreignOrganization->id);
        $fixture->member->assignedProjects()->attach($visibleProject->id, ['is_active' => true, 'role' => 'member']);

        foreach ([
            [$fixture->organization->id, $visibleProject, 'visible.ifc', $fixture->owner],
            [$fixture->organization->id, $hiddenProject, 'hidden.ifc', $fixture->owner],
            [$fixture->foreignOrganization->id, $foreignProject, 'foreign.ifc', $fixture->foreignOwner],
        ] as [$organizationId, $project, $name, $fileOwner]) {
            $version = $this->version((int) $organizationId, $project, $name);
            $this->attachFile($version, $fileOwner);
        }

        $coverage = app(AssistantDocumentCoverageService::class)->coverage((int) $fixture->organization->id, $fixture->member)['document_coverage'];
        self::assertSame(1, $coverage['total']);
        self::assertSame(1, $coverage['pending']);
        self::assertSame(0, $coverage['ready']);
        self::assertSame(0, $coverage['processed_units']);
    }

    private function useTestEmbeddingProvider(): AssistantDesignIfcTestEmbeddingProvider
    {
        $provider = new AssistantDesignIfcTestEmbeddingProvider;
        $this->app->instance(RagEmbeddingProviderInterface::class, $provider);

        return $provider;
    }

    private function project(int $organizationId): Project
    {
        return Project::withoutEvents(fn (): Project => Project::factory()->create(['organization_id' => $organizationId]));
    }

    private function version(int $organizationId, Project $project, string $name): DesignArtifactVersion
    {
        $package = DesignPackage::withoutEvents(fn (): DesignPackage => DesignPackage::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $project->id,
            'title' => 'Пакет '.$name,
            'status' => 'draft',
        ]));
        $artifact = DesignArtifact::withoutEvents(fn (): DesignArtifact => DesignArtifact::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $project->id,
            'package_id' => $package->id,
            'title' => 'Модель '.$name,
            'artifact_type' => 'model',
        ]));

        return DesignArtifactVersion::withoutEvents(fn (): DesignArtifactVersion => DesignArtifactVersion::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $project->id,
            'artifact_id' => $artifact->id,
            'title' => 'Версия '.$name,
            'version_number' => '1',
            'source_format' => 'ifc',
            'file_format' => 'ifc',
            'source_file_path' => 'ifc-tests/'.Str::uuid().'.ifc',
            'source_original_name' => $name,
            'source_mime_type' => 'application/octet-stream',
            'source_size_bytes' => 100,
            'source_sha256' => hash('sha256', $name),
        ]));
    }

    private function attachFile(DesignArtifactVersion $version, User $owner): File
    {
        return File::withoutEvents(fn (): File => File::query()->create([
            'organization_id' => $version->organization_id,
            'fileable_type' => $version->getMorphClass(),
            'fileable_id' => $version->id,
            'user_id' => $owner->id,
            'name' => $version->source_original_name,
            'original_name' => $version->source_original_name,
            'path' => $version->source_file_path,
            'mime_type' => $version->source_mime_type,
            'size' => $version->source_size_bytes,
            'disk' => 's3',
            'type' => 'document',
            'category' => 'ai_assistant',
            'additional_info' => [
                'assistant_native_source' => 'design',
                'design_source_version' => 'fixture',
                'design_source_sha256' => $version->source_sha256,
            ],
        ]));
    }

    private function element(int $organizationId, Project $project, DesignArtifactVersion $version, int $expressId): DesignIfcModelElement
    {
        return DesignIfcModelElement::withoutEvents(fn (): DesignIfcModelElement => DesignIfcModelElement::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $project->id,
            'version_id' => $version->id,
            'express_id' => $expressId,
            'global_id' => 'global-'.$expressId,
            'category' => 'IfcWall',
            'name' => 'Стена '.$expressId,
            'properties' => ['materials' => ['Бетон'], 'quantities' => ['Qto_Wall' => ['NetArea' => 3.5]]],
        ]));
    }
}

final class AssistantDesignIfcTestEmbeddingProvider implements RagEmbeddingProviderInterface
{
    public int $calls = 0;

    public int $maxTextChars = 0;

    public function embed(string $text, string $purpose = self::PURPOSE_DOCUMENT): array
    {
        $this->calls++;
        $this->maxTextChars = max($this->maxTextChars, mb_strlen($text, 'UTF-8'));

        return array_fill(0, $this->dimensions(), 0.01);
    }

    public function provider(): string
    {
        return 'ifc-test';
    }

    public function model(): string
    {
        return 'ifc-test-model';
    }

    public function dimensions(): int
    {
        return 256;
    }
}
