<?php

declare(strict_types=1);

namespace Tests\Feature\ConstructionJournal;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\GeneralJournalDocumentVersion;
use App\Models\Project;
use App\Services\ConstructionJournal\GeneralJournalDocumentService;
use App\Services\LegalArchive\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class GeneralJournalDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_preserves_approved_work_and_replay_after_source_change(): void
    {
        [$context, $journal, $entry] = $this->fixture();
        $service = app(GeneralJournalDocumentService::class);
        $payload = $this->payload();
        $first = $service->prepare($context->user, $journal, $payload);

        self::assertSame(1, $first->revision);
        self::assertCount(6, $first->source_snapshot['sections']);
        self::assertCount(1, $first->source_snapshot['sections'][3]);
        self::assertStringContainsString('Approved work', $first->source_snapshot['sections'][3][0]['works']);
        $entry->update(['work_description' => 'Changed source']);
        $journal->project->update(['name' => 'Changed project']);

        $replayed = $service->prepare($context->user, $journal->fresh(), $payload);
        self::assertSame($first->id, $replayed->id);
        self::assertSame(CanonicalJson::encode($first->source_snapshot), CanonicalJson::encode($replayed->source_snapshot));
        self::assertSame($first->snapshot_hash, $replayed->snapshot_hash);
    }

    public function test_correction_requires_current_revision_and_preserves_previous_version(): void
    {
        [$context, $journal] = $this->fixture();
        $service = app(GeneralJournalDocumentService::class);
        $first = $service->prepare($context->user, $journal, $this->payload());
        $payload = [...$this->payload(), 'operation_key' => 'second', 'expected_revision' => 1];
        try {
            $service->prepare($context->user, $journal, $payload);
            self::fail('Correction reason is required.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }
        $second = $service->prepare($context->user, $journal, [...$payload, 'correction_reason' => 'Corrected title details']);
        self::assertSame(2, $second->revision);
        self::assertSame($first->id, $second->previous_version_id);
        self::assertSame(CanonicalJson::encode($first->source_snapshot), CanonicalJson::encode($first->fresh()->source_snapshot));
        self::assertSame(2, GeneralJournalDocumentVersion::query()->count());
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(409);
        $service->prepare($context->user, $journal, [...$payload, 'operation_key' => 'stale', 'correction_reason' => 'Stale edit']);
    }

    public function test_changed_replay_and_electronic_mode_are_rejected(): void
    {
        [$context, $journal] = $this->fixture();
        $service = app(GeneralJournalDocumentService::class);
        $service->prepare($context->user, $journal, $this->payload());
        try {
            $service->prepare($context->user, $journal, [...$this->payload(), 'correction_reason' => 'Changed body']);
            self::fail('Changed operation must conflict.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
        }
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(422);
        $service->prepare($context->user, $journal, [...$this->payload(), 'mode' => 'electronic']);
    }

    public function test_foreign_organization_cannot_create_or_read_versions(): void
    {
        [$context, $journal] = $this->fixture();
        $foreign = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(404);
        app(GeneralJournalDocumentService::class)->prepare($foreign->user, $journal, $this->payload());
    }

    private function payload(): array
    {
        return ['mode' => 'paper_preparation', 'expected_revision' => 0, 'operation_key' => 'first', 'profile' => [], 'work_details' => [], 'document_version_ids' => []];
    }

    public function test_http_preparation_queues_same_version_once_and_hides_foreign_history(): void
    {
        Bus::fake();
        [$context, $journal] = $this->fixture();
        $url = '/api/v1/admin/construction-journals/'.$journal->id.'/export/general';
        $first = $this->withHeaders($context->authHeaders())->postJson($url, $this->payload())->assertStatus(202);
        $replay = $this->withHeaders($context->authHeaders())->postJson($url, $this->payload())->assertStatus(202);
        self::assertSame($first->json('data.version.id'), $replay->json('data.version.id'));
        self::assertSame($first->json('data.export.id'), $replay->json('data.export.id'));
        self::assertSame(1, \App\Models\JournalExport::query()->count());
        Bus::assertDispatchedTimes(\App\Jobs\ConstructionJournal\GenerateJournalExportJob::class, 1);
        $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/construction-journals/'.$journal->id.'/general-document')
            ->assertOk()->assertJsonPath('data.version.revision', 1);
        $foreign = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->withHeaders($foreign->authHeaders())->getJson('/api/v1/admin/construction-journals/'.$journal->id.'/general-document')->assertForbidden()->assertJsonPath('data', null);
    }

    public function test_database_rejects_snapshot_overwrite_and_delete(): void
    {
        [$context, $journal] = $this->fixture();
        $version = app(GeneralJournalDocumentService::class)->prepare($context->user, $journal, $this->payload());
        foreach (['update', 'delete'] as $operation) {
            try {
                DB::transaction(function () use ($operation, $version): void {
                    $query = DB::table('general_journal_document_versions')->where('id', $version->id);
                    $operation === 'update' ? $query->update(['correction_reason' => 'Overwritten']) : $query->delete();
                });
                self::fail('Immutable journal version must survive '.$operation);
            } catch (\Illuminate\Database\QueryException $exception) {
                self::assertStringContainsString('general_journal_version_immutable', $exception->getMessage());
            }
        }
        self::assertSame($version->snapshot_hash, $version->fresh()->snapshot_hash);
    }

    public function test_manual_sections_cannot_replace_work_chronology_and_unapproved_details_are_rejected(): void
    {
        [$context, $journal] = $this->fixture();
        $service = app(GeneralJournalDocumentService::class);
        try {
            $service->prepare($context->user, $journal, [...$this->payload(), 'profile' => ['sections' => [3 => [['works' => 'Injected work']]]]]);
            self::fail('Generated section must not be client-controlled.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }
        $draftId = $journal->entries()->where('status', 'draft')->value('id');
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(422);
        $service->prepare($context->user, $journal, [...$this->payload(), 'work_details' => [['entry_id' => $draftId, 'location' => 'Draft location']]]);
    }

    public function test_document_sources_require_separate_permission_in_project_scope(): void
    {
        [$context, $journal] = $this->fixture();
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnUsing(
            function ($user, string $permission, array $scope) use ($journal): bool {
                self::assertSame((int) $journal->organization_id, $scope['organization_id']);
                self::assertSame((int) $journal->project_id, $scope['project_id']);
                self::assertTrue($scope['strict_project_scope']);
                return $permission !== 'executive-documentation.view';
            },
        );
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(403);
        app(GeneralJournalDocumentService::class)->prepare($context->user, $journal, [...$this->payload(), 'document_version_ids' => [1]]);
    }

    public function test_worker_generates_private_pdf_and_does_not_complete_when_storage_fails(): void
    {
        Bus::fake();
        \Illuminate\Support\Facades\Storage::fake('s3');
        [$context, $journal, $entry] = $this->fixture();
        $version = app(GeneralJournalDocumentService::class)->prepare($context->user, $journal, $this->payload());
        $workflow = app(\App\Services\ConstructionJournal\JournalExportWorkflowService::class);
        $export = $workflow->request($context->user, $journal, 'general', 'pdf', ['document_version_id' => $version->id], 'worker-first');
        $entry->update(['work_description' => 'Changed after export request']);
        $job = new \App\Jobs\ConstructionJournal\GenerateJournalExportJob($export->id);
        $officialFiles = app(\App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService::class);
        $realFiles = app(\App\Services\Storage\FileService::class);
        $privateFiles = \Mockery::mock($realFiles);
        $privateFiles->shouldReceive('putContent')->once()->andReturnUsing(
            function ($content, $directory, $filename, $visibility, $organization) use ($realFiles, $context) {
                self::assertSame('private', $visibility);
                self::assertSame($context->organization->id, $organization->id);
                return $realFiles->putContent($content, $directory, $filename, $visibility, $organization);
            },
        );
        $this->app->instance(\App\Services\Storage\FileService::class, $privateFiles);
        $job->handle($officialFiles);
        $export->refresh();
        self::assertSame('completed', $export->status);
        self::assertStringStartsWith('org-'.$context->organization->id.'/exports/journal/general/', $export->result_path);
        self::assertStringStartsWith('%PDF', \Illuminate\Support\Facades\Storage::disk('s3')->get($export->result_path));

        $failed = $workflow->request($context->user, $journal, 'general', 'pdf', ['document_version_id' => $version->id], 'worker-storage-failure');
        $this->mock(\App\Services\Storage\FileService::class)->shouldReceive('putContent')->once()->andReturnFalse();
        try {
            (new \App\Jobs\ConstructionJournal\GenerateJournalExportJob($failed->id))->handle($officialFiles);
            self::fail('Failed storage write must not complete an export.');
        } catch (\RuntimeException $exception) {
            self::assertSame('general_journal_storage_failed', $exception->getMessage());
        }
        self::assertNull($failed->fresh()->result_path);
        self::assertNotSame('completed', $failed->fresh()->status);
    }

    private function fixture(): array
    {
        $this->mock(AuthorizationService::class, function ($mock): void {
            $mock->shouldReceive('can')->andReturnTrue();
            $mock->shouldReceive('canAccessInterface')->andReturnTrue();
            $mock->shouldReceive('hasRole')->andReturnTrue();
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
        });
        $this->mock(\App\Modules\Core\AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $journal = ConstructionJournal::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'name' => 'General journal', 'journal_number' => 'GENERAL-1', 'start_date' => '2026-09-20',
            'status' => 'active', 'created_by_user_id' => $context->user->id,
        ]);
        $entry = null;
        foreach (['approved', 'draft'] as $index => $status) {
            $created = ConstructionJournalEntry::query()->create([
                'journal_id' => $journal->id, 'entry_date' => '2026-09-20', 'entry_number' => $index + 1,
                'work_description' => $status === 'approved' ? 'Approved work' : 'Private draft',
                'status' => $status, 'created_by_user_id' => $context->user->id,
                'approved_by_user_id' => $status === 'approved' ? $context->user->id : null,
                'approved_at' => $status === 'approved' ? now() : null,
            ]);
            $entry ??= $created;
        }
        return [$context, $journal, $entry];
    }
}
