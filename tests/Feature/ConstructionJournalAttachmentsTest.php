<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalPayloadService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\ProjectOrganizationRole;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\JournalEntryAttachment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\ConstructionJournal\EntryAttachmentService;
use App\Services\Logging\LoggingService;
use App\Services\Mobile\MobileConstructionJournalService;
use App\Services\Project\ProjectParticipantService;
use App\Services\Storage\FileService;
use Aws\Result;
use Aws\S3\S3ClientInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class ConstructionJournalAttachmentsTest extends TestCase
{
    use EnablesImmutableAuditWriter;

    private int $issuedLinks = 0;

    private array $privateWrites = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableImmutableAuditWriter();
        $this->mock(AuthorizationService::class, static function ($mock): void {
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(false);
        });
        Storage::fake('s3');
    }

    public function test_private_upload_metadata_round_trips_and_parent_can_read_without_uploading(): void
    {
        [$project, $journal, $entry, $parent, $performer, $sibling] = $this->fixture();
        $this->fakeFiles();
        $service = app(EntryAttachmentService::class);
        $metadata = $service->upload($performer, $entry, $this->image('../../Проверка<script>.svg'));
        $attachment = JournalEntryAttachment::query()->findOrFail($metadata['id']);

        self::assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $metadata['id']);
        self::assertSame('image/png', $metadata['mime_type']);
        self::assertStringEndsWith('.png', $metadata['file_name']);
        self::assertStringNotContainsString('/', $metadata['file_name']);
        self::assertStringNotContainsString('<', $metadata['file_name']);
        self::assertGreaterThan(0, $metadata['size']);
        self::assertSame(['id' => $performer->id, 'name' => $performer->name, 'organization_id' => (int) $performer->current_organization_id], $metadata['uploaded_by']);
        self::assertArrayNotHasKey('url', $metadata);
        self::assertArrayNotHasKey('storage_key', $metadata);
        self::assertStringStartsWith('org-'.$project->organization_id.'/construction-journals/'.$journal->id.'/entries/'.$entry->id.'/attachments/', $attachment->storage_key);
        self::assertSame('private', $this->privateWrites[$attachment->storage_key]['ACL']);
        self::assertSame('*', $this->privateWrites[$attachment->storage_key]['IfNoneMatch']);
        self::assertSame([$metadata], $service->list($parent, $entry, $journal));

        $performer->update(['name' => 'Имя изменилось']);
        self::assertSame($metadata['uploaded_by'], $service->list($performer, $entry)[0]['uploaded_by']);
        $entry = $entry->fresh(ConstructionJournalPayloadService::ENTRY_RELATIONS);
        $adminPayload = app(ConstructionJournalPayloadService::class)->mapEntry($entry, $performer);
        $mobilePayload = app(MobileConstructionJournalService::class)->mapMobileEntry($entry, $performer);
        self::assertSame([$metadata], $adminPayload['attachments']);
        self::assertSame([$metadata], $mobilePayload['attachments']);
        self::assertContains('upload_attachment', $adminPayload['available_actions']);
        self::assertContains('upload_attachment', array_column($mobilePayload['available_actions'], 'action'));
        self::assertSame('Добавить фото', collect($mobilePayload['available_actions'])->firstWhere('action', 'upload_attachment')['label']);
        self::assertNotContains('upload_attachment', app(ConstructionJournalPayloadService::class)->mapEntry($entry, $parent)['available_actions']);

        $attachment->file_name = 'Изменённое имя.png';
        try {
            $attachment->save();
            self::fail('Метаданные вложения неизменяемы');
        } catch (LogicException) {
            self::assertSame($metadata['file_name'], $attachment->fresh()->file_name);
        }
    }

    public function test_every_download_checks_branch_and_nested_attachment_and_journal_ownership(): void
    {
        [$project, $journal, $entry, $parent, $performer, $sibling] = $this->fixture();
        $this->fakeFiles();
        $service = app(EntryAttachmentService::class);
        $metadata = $service->upload($performer, $entry, $this->image());
        $attachment = JournalEntryAttachment::query()->findOrFail($metadata['id']);
        $otherJournal = $this->journal($project, $performer, 'OTHER');
        $otherEntry = $this->entry($otherJournal, $performer);

        foreach ([[$sibling, $entry, null], [$performer, $otherEntry, null], [$performer, $entry, $otherJournal]] as [$actor, $target, $journalContext]) {
            try {
                $service->download($actor, $target, $attachment, $journalContext);
                self::fail('Чужое вложение недоступно');
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(0, $this->issuedLinks);
        self::assertArrayHasKey('expires_at', $service->download($parent, $entry, $attachment));
        self::assertSame(1, $this->issuedLinks);
        $project->users()->updateExistingPivot($parent->id, ['is_active' => false]);
        try {
            $service->download($parent, $entry, $attachment);
            self::fail('После отзыва доступа новую ссылку нельзя выдать');
        } catch (AuthorizationException) {
            self::assertSame(1, $this->issuedLinks);
        }
    }

    public function test_upload_requires_current_performer_entry_update_permission_and_editable_states(): void
    {
        [$project, $journal, $entry, $parent, $performer, $sibling] = $this->fixture();
        $this->fakeFiles();
        $service = app(EntryAttachmentService::class);
        foreach ([$parent, $sibling] as $actor) {
            try {
                $service->upload($actor, $entry, $this->image());
                self::fail('Загрузить фото может только исполнитель');
            } catch (AuthorizationException) {
                self::assertSame(0, JournalEntryAttachment::query()->count());
            }
        }
        foreach (['submitted', 'approved'] as $status) {
            $entry->update(['status' => $status]);
            try {
                $service->upload($performer, $entry, $this->image());
                self::fail('Фото нельзя менять после передачи записи');
            } catch (AuthorizationException) {
                self::assertSame(0, JournalEntryAttachment::query()->count());
            }
        }
        $entry->update(['status' => 'rejected']);
        self::assertArrayHasKey('id', $service->upload($performer, $entry, $this->image()));
        $journal->update(['status' => 'closed']);
        $entry->setRelation('journal', $journal);
        $this->expectException(AuthorizationException::class);
        $service->upload($performer, $entry, $this->image());
    }

    public function test_upload_without_edit_permission_and_invalid_or_oversized_files_are_rejected(): void
    {
        [$project, $journal, $entry, $parent, $performer] = $this->fixture();
        $this->fakeFiles();
        $this->useAttachmentHttpHarness();
        $base = '/api/v1/mobile/journal-entries/'.$entry->id.'/attachments';
        $this->actingAs($performer, 'api_mobile')
            ->postJson($base, ['file' => UploadedFile::fake()->create('large.png', 10241, 'image/png')])
            ->assertUnprocessable();
        $this->actingAs($performer, 'api_mobile')
            ->postJson($base, ['file' => UploadedFile::fake()->createWithContent('spoof.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')])
            ->assertUnprocessable();
        self::assertSame([], Storage::disk('s3')->allFiles());
        self::assertSame(0, JournalEntryAttachment::query()->count());

        $this->mock(AuthorizationService::class, static function ($mock): void {
            $mock->shouldReceive('can')->andReturnUsing(static fn (User $actor, string $permission): bool => $permission === 'construction-journal.view');
            $mock->shouldReceive('hasRole')->andReturn(false);
        });
        $this->expectException(AuthorizationException::class);
        app(EntryAttachmentService::class)->upload($performer, $entry, $this->image());
    }

    public function test_upload_compensates_object_when_storage_fails_after_write_and_metadata_rolls_back(): void
    {
        [$project, $journal, $entry, $parent, $performer] = $this->fixture();
        $this->fakeFiles(failAfterWrite: true);
        try {
            app(EntryAttachmentService::class)->upload($performer, $entry, $this->image());
            self::fail('Ошибку хранилища нельзя принять за успешную загрузку');
        } catch (RuntimeException $exception) {
            self::assertSame('upload_failed_after_write', $exception->getMessage());
        }
        self::assertSame(0, JournalEntryAttachment::query()->count());
        self::assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_mobile_and_admin_upload_routes_use_same_metadata_and_reject_wrong_journal_parent(): void
    {
        [$project, $journal, $entry, $parent, $performer, $sibling] = $this->fixture();
        $this->fakeFiles();
        $this->useAttachmentHttpHarness();
        $response = $this->actingAs($performer, 'api_mobile')
            ->postJson('/api/v1/mobile/journal-entries/'.$entry->id.'/attachments', ['file' => $this->image()])
            ->assertCreated()->assertJsonPath('data.mime_type', 'image/png');
        $id = $response->json('data.id');
        $this->actingAs($parent, 'api_admin')
            ->getJson('/api/v1/admin/construction-journals/'.$journal->id.'/entries/'.$entry->id.'/attachments')
            ->assertOk()->assertJsonPath('data.0.id', $id);
        $this->actingAs($parent, 'api_mobile')
            ->getJson('/api/v1/mobile/journal-entries/'.$entry->id.'/attachments/'.$id.'/download')
            ->assertOk()->assertJsonPath('data.url', 'https://files.example.test/download?signature=private');
        $this->actingAs($sibling, 'api_mobile')
            ->getJson('/api/v1/mobile/journal-entries/'.$entry->id.'/attachments')
            ->assertForbidden();
        $this->actingAs($performer, 'api_admin')
            ->getJson('/api/v1/admin/construction-journals/'.$journal->id.'/entries/'.$entry->id.'/attachments/'.$id.'/download')
            ->assertOk();
        $otherJournal = $this->journal($project, $performer, 'ANOTHER');
        $this->actingAs($performer, 'api_admin')
            ->postJson('/api/v1/admin/construction-journals/'.$otherJournal->id.'/entries/'.$entry->id.'/attachments', ['file' => $this->image()])
            ->assertForbidden();
        self::assertSame(1, JournalEntryAttachment::query()->count());
    }

    private function useAttachmentHttpHarness(): void
    {
        $router = app('router');
        $middleware = app(Kernel::class)->getGlobalMiddleware();
        foreach ($router->getRoutes() as $route) {
            if (str_contains($route->uri(), '/attachments')
                && (str_contains($route->uri(), 'journal-entries/') || str_contains($route->uri(), 'construction-journals/'))) {
                $middleware = [...$middleware, ...$router->gatherRouteMiddleware($route)];
            }
        }
        $middleware = array_unique(array_map(static fn (string $class): string => explode(':', $class, 2)[0], $middleware));
        $this->withoutMiddleware(array_values(array_filter($middleware, static fn (string $class): bool => $class !== SubstituteBindings::class)));
    }

    private function fakeFiles(bool $failAfterWrite = false): void
    {
        $client = Mockery::mock(S3ClientInterface::class);
        $client->shouldReceive('putObject')->andReturnUsing(function (array $arguments) use ($failAfterWrite): Result {
            self::assertSame('private', $arguments['ACL']);
            self::assertSame('*', $arguments['IfNoneMatch']);
            self::assertContains($arguments['ContentType'], ['image/png', 'image/jpeg', 'image/webp']);
            self::assertSame((new \finfo(FILEINFO_MIME_TYPE))->buffer($arguments['Body']), $arguments['ContentType']);
            self::assertSame(hash('sha256', $arguments['Body']), $arguments['Metadata']['sha256']);
            $this->privateWrites[$arguments['Key']] = $arguments;
            Storage::disk('s3')->put($arguments['Key'], $arguments['Body']);
            if ($failAfterWrite) {
                throw new RuntimeException('upload_failed_after_write');
            }

            return new Result(['ETag' => '"immutable-object-etag"']);
        });
        $issueLink = function (): string {
            $this->issuedLinks++;

            return 'https://files.example.test/download?signature=private';
        };
        Storage::disk('s3')->buildTemporaryUrlsUsing(fn (): string => $issueLink());
        $logging = new class extends LoggingService
        {
            public function __construct() {}
        };
        $files = new class($logging, $client) extends FileService
        {
            public function __construct(LoggingService $logging, private readonly S3ClientInterface $client)
            {
                parent::__construct($logging);
            }

            protected function s3Client(): S3ClientInterface
            {
                return $this->client;
            }

            protected function reportBucket(): string
            {
                return 'journal-attachments-test';
            }
        };
        $this->instance(FileService::class, $files);
    }

    private function fixture(): array
    {
        $organizations = [];
        $users = [];
        for ($index = 0; $index < 3; $index++) {
            $organization = Organization::factory()->create(['is_active' => true]);
            $user = User::factory()->create(['current_organization_id' => $organization->id]);
            $user->organizations()->attach($organization->id, ['is_active' => true, 'is_owner' => true, 'project_access_mode' => 'assigned_projects']);
            $organizations[] = $organization;
            $users[] = $user;
        }
        $project = Project::factory()->create(['organization_id' => $organizations[0]->id, 'is_archived' => false]);
        foreach ([1, 2] as $index) {
            $project->organizations()->attach($organizations[$index]->id, ['is_active' => true, 'role' => 'child_contractor', 'role_new' => ProjectOrganizationRole::SUBCONTRACTOR->value]);
        }
        foreach ($users as $user) {
            $project->users()->attach($user->id, ['is_active' => true]);
        }
        app(ProjectParticipantService::class)->saveHierarchy($project, $organizations[0]->id, [
            ['organization_id' => $organizations[1]->id, 'parent_organization_id' => $organizations[0]->id],
            ['organization_id' => $organizations[2]->id, 'parent_organization_id' => $organizations[0]->id],
        ]);
        $journal = $this->journal($project, $users[1], 'ATTACHMENTS');
        $entry = $this->entry($journal, $users[1]);

        return [$project, $journal, $entry, ...$users];
    }

    private function journal(Project $project, User $performer, string $number): ConstructionJournal
    {
        return ConstructionJournal::query()->create([
            'organization_id' => $project->organization_id,
            'performing_organization_id' => $performer->current_organization_id,
            'project_id' => $project->id,
            'name' => 'Журнал фото',
            'journal_number' => $number,
            'start_date' => '2026-10-11',
            'status' => 'active',
            'created_by_user_id' => $performer->id,
        ]);
    }

    private function entry(ConstructionJournal $journal, User $performer): ConstructionJournalEntry
    {
        return ConstructionJournalEntry::query()->create([
            'journal_id' => $journal->id,
            'entry_date' => '2026-10-11',
            'entry_number' => 1,
            'work_description' => 'Фото выполненных работ',
            'status' => 'draft',
            'created_by_user_id' => $performer->id,
        ]);
    }

    private function image(string $name = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='))->mimeType('image/png');
    }
}
