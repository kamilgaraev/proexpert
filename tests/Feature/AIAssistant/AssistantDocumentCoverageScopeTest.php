<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentReadinessReader;
use App\BusinessModules\Features\Tenders\Models\Tender;
use App\BusinessModules\Features\Tenders\Models\TenderFile;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\File;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantDocumentCoverageScopeTest extends TestCase
{
    private function fixture(): AssistantRealAuthorizationFixture
    {
        Queue::fake(); config(['cache.default' => 'array']);
        return Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
    }

    public function test_file_parent_project_scope_excludes_other_readable_project_before_count(): void
    {
        $f = $this->fixture(); $org = (int) $f->organization->id;
        $projects = []; $journals = [];
        Model::withoutEvents(function () use ($f, $org, &$projects, &$journals): void {
            foreach (['OWN', 'OTHER'] as $number) {
                $project = Project::factory()->create(['organization_id' => $org]); $projects[] = $project;
                $journal = ConstructionJournal::query()->create(['organization_id' => $org, 'performing_organization_id' => $org, 'project_id' => $project->id,
                    'name' => $number, 'journal_number' => $number, 'start_date' => today(), 'status' => 'active', 'created_by_user_id' => $f->owner->id]); $journals[] = $journal;
                $entry = ConstructionJournalEntry::query()->create(['journal_id' => $journal->id, 'entry_date' => today(), 'entry_number' => 1,
                    'work_description' => 'PRIVATE_CONTENT', 'status' => 'draft', 'created_by_user_id' => $f->owner->id]);
                File::query()->create(['organization_id' => $org, 'user_id' => $f->owner->id, 'fileable_type' => $entry->getMorphClass(), 'fileable_id' => $entry->id,
                    'name' => $number.'.txt', 'original_name' => $number.'.txt', 'path' => 'org-'.$org.'/scope/'.$number.'.txt', 'disk' => 's3', 'mime_type' => 'text/plain', 'size' => 3]);
            }
        });
        $coverage = app(AssistantDocumentCoverageService::class);
        $all = $coverage->coverage($org, $f->owner, countersOnly: true);
        self::assertSame(['document_coverage'], array_keys($all)); self::assertSame(2, $all['document_coverage']['total']);
        $args = ['project_id' => (int) $projects[0]->id]; $reader = app(AssistantDocumentReadinessReader::class);
        $scoped = $reader->read($f->owner, $org, $args);
        self::assertSame(0, $scoped['data']['total']); self::assertSame(1, $scoped['data']['file_coverage']['total']);
        self::assertSame(1, $scoped['data']['file_coverage']['pending']);
        self::assertStringNotContainsString('PRIVATE', json_encode($scoped)); self::assertStringNotContainsString('OTHER', json_encode($scoped));
        $journals[0]->updateQuietly(['project_id' => $projects[1]->id]);
        self::assertFalse($reader->matchesReference($f->owner, $org, $scoped['source_refs'][0]));
        self::assertSame(0, $reader->read($f->owner, $org, $args)['data']['file_coverage']['total']);
    }

    public function test_metadata_only_attachment_state_is_counted_and_not_claimed_as_ready(): void
    {
        $f = $this->fixture(); $org = (int) $f->organization->id;
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org]));
        Model::withoutEvents(function () use ($f, $org, $project): void {
            $tender = Tender::query()->create(['organization_id' => $org, 'project_id' => $project->id, 'number' => 'DOC-SCOPE-'.$org, 'title' => 'Тендер']);
            TenderFile::query()->create(['tender_id' => $tender->id, 'category' => 'general', 'original_name' => 'PRIVATE_NAME.pdf',
                'stored_path' => 'private-native/'.$org.'/document.pdf', 'mime_type' => 'application/pdf', 'size' => 17, 'uploaded_at' => now()]);
        });
        $reader = app(AssistantDocumentReadinessReader::class); $args = ['project_id' => (int) $project->id];
        $result = $reader->read($f->owner, $org, $args);
        self::assertSame(0, $result['data']['total']); self::assertSame(1, $result['data']['file_coverage']['total']);
        self::assertSame(1, $result['data']['file_coverage']['storage_unverified']); self::assertSame(0, $result['data']['file_coverage']['ready']);
        self::assertStringContainsString('содержимое и хранилище вложения не проверены: 1', $result['server_formatted_answer']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result)); self::assertStringNotContainsString('private-native', json_encode($result));
    }
}
