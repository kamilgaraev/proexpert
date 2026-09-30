<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagDispatchIntent;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagJobDispatcher;
use App\Jobs\ProcessAssistantDocument;
use App\Jobs\RegisterAssistantEntityFile;
use App\Jobs\ScanAssistantDocuments;
use App\Models\File;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

final class RagFileProducerDurabilityTest extends TestCase
{
    public function test_file_save_intent_rolls_back_and_does_not_dispatch(): void
    {
        Queue::fake();
        $file = $this->file();
        DB::beginTransaction();
        $file->update(['original_name' => 'changed.txt']);
        self::assertSame(1, $this->runs($file)->count());
        Queue::assertNotPushed(IndexRagSourceJob::class);
        DB::rollBack();
        self::assertSame('source.txt', $file->fresh()->original_name);
        self::assertSame(0, $this->runs($file)->count());
        Queue::assertNotPushed(IndexRagSourceJob::class);
    }

    public function test_registration_child_dispatch_failure_keeps_run_retryable_and_reuses_document(): void
    {
        Queue::fake();
        $file = $this->file();
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('readCurrentBounded')->andReturnUsing(static function () {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, 'document content');
            rewind($stream);
            return $stream;
        });
        $this->app->instance(FileService::class, $files);
        $ragBus = $this->createMock(Dispatcher::class);
        $coordinator = new RagIndexingCoordinator(app(RagIndexer::class), new RagJobDispatcher($ragBus, $this->createMock(LoggerInterface::class)));
        $this->app->instance(RagIndexingCoordinator::class, $coordinator);
        $run = $coordinator->queueEntity((int) $file->organization_id, null, 'file_document', 'file', (string) $file->id);
        $attempts = 0;
        $bus = $this->createMock(Dispatcher::class);
        $bus->expects($this->exactly(2))->method('dispatch')->willReturnCallback(static function ($job) use (&$attempts): string {
            self::assertInstanceOf(ProcessAssistantDocument::class, $job);
            if (++$attempts === 1) {
                throw new \RuntimeException('Redis unavailable');
            }
            return 'accepted';
        });
        $this->app->instance(Dispatcher::class, $bus);
        $job = new IndexRagSourceJob((int) $file->organization_id, null, 'file_document', $run->id, 'file', (string) $file->id);
        try {
            $job->handle(app(RagIndexer::class), $coordinator);
            self::fail('Child dispatch must fail on the first attempt');
        } catch (\RuntimeException $exception) {
            self::assertSame('Redis unavailable', $exception->getMessage());
        }
        self::assertSame(RagIndexRun::STATUS_QUEUED, $run->fresh()->status);
        self::assertSame(\RuntimeException::class, $run->fresh()->last_error);
        self::assertSame(1, AIAssistantDocument::query()->where('file_id', $file->id)->count());
        $this->travel(2)->minutes();
        self::assertGreaterThanOrEqual(1, $coordinator->recoverExpiredRuns());
        $job->handle(app(RagIndexer::class), $coordinator);
        self::assertSame(RagIndexRun::STATUS_SUCCEEDED, $run->fresh()->status);
        self::assertSame(1, AIAssistantDocument::query()->where('file_id', $file->id)->count());
    }

    public function test_registration_of_old_organization_does_not_read_reassigned_file(): void
    {
        Queue::fake();
        $file = $this->file();
        $oldOrganization = (int) $file->organization_id;
        $other = Model::withoutEvents(fn () => Organization::factory()->create());
        $file->updateQuietly(['organization_id' => $other->id]);
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('readCurrentBounded');
        $this->app->instance(FileService::class, $files);
        (new RegisterAssistantEntityFile((int) $file->id, $oldOrganization))
            ->handle(app(\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentService::class));
        self::assertSame(0, AIAssistantDocument::query()->where('file_id', $file->id)->count());
        Queue::assertNotPushed(ProcessAssistantDocument::class);
    }

    public function test_archive_cursor_and_file_intent_persist_despite_dispatch_failure(): void
    {
        Queue::fake();
        $file = $this->file();
        $bus = $this->createMock(Dispatcher::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('Redis unavailable'));
        $this->app->instance(RagIndexingCoordinator::class, new RagIndexingCoordinator(app(RagIndexer::class), new RagJobDispatcher($bus, $this->createMock(LoggerInterface::class))));
        (new ScanAssistantDocuments((int) $file->organization_id))->handle();
        $this->assertDatabaseHas('ai_assistant_document_settings', ['organization_id' => $file->organization_id, 'last_file_id' => $file->id]);
        $run = $this->runs($file)->firstOrFail();
        self::assertSame(RagIndexRun::STATUS_QUEUED, $run->status);
        self::assertSame(\RuntimeException::class, RagDispatchIntent::publicError($run->last_error));
        self::assertTrue(RagDispatchIntent::isPending($run->last_error));
        (new ScanAssistantDocuments((int) $file->organization_id))->handle();
        self::assertSame(1, $this->runs($file)->count());
    }

    public function test_archive_registration_stays_on_bulk_queue_and_live_change_promotes_same_intent(): void
    {
        Queue::fake();
        $file = $this->file();
        $coordinator = app(RagIndexingCoordinator::class);
        $run = $coordinator->queueFileRegistration((int) $file->organization_id, (int) $file->id, true);
        self::assertSame(RagIndexRun::MODE_SCHEDULED, $run->mode);
        Queue::assertPushed(IndexRagSourceJob::class, fn (IndexRagSourceJob $job): bool => $job->runId === $run->id && $job->queue === config('ai-assistant.rag.queue'));
        $file->update(['original_name' => 'live.txt']);
        self::assertSame(RagIndexRun::MODE_ASYNC, $run->fresh()->mode);
        self::assertSame(1, $this->runs($file)->count());
        Queue::assertPushed(IndexRagSourceJob::class, fn (IndexRagSourceJob $job): bool => $job->runId === $run->id && $job->queue === config('ai-assistant.rag.live_queue'));
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('readCurrentBounded')->once()->andReturnUsing(static function () {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, 'current content');
            rewind($stream);
            return $stream;
        });
        $this->app->instance(FileService::class, $files);
        $oldBulk = new IndexRagSourceJob((int) $file->organization_id, null, 'file_document', $run->id, 'file', (string) $file->id);
        $oldBulk->onQueue((string) config('ai-assistant.rag.queue'));
        $live = new IndexRagSourceJob((int) $file->organization_id, null, 'file_document', $run->id, 'file', (string) $file->id);
        $oldBulk->handle(app(RagIndexer::class), $coordinator);
        $live->handle(app(RagIndexer::class), $coordinator);
        self::assertSame(RagIndexRun::STATUS_SUCCEEDED, $run->fresh()->status);
        self::assertSame('live.txt', AIAssistantDocument::query()->where('file_id', $file->id)->firstOrFail()->filename);
        Queue::assertPushed(ProcessAssistantDocument::class, 1);
        Queue::assertNotPushed(IndexRagSourceJob::class, fn (IndexRagSourceJob $job): bool => $job->entityType === 'assistant_document');
    }

    private function runs(File $file): \Illuminate\Database\Eloquent\Builder
    {
        return RagIndexRun::query()->where('organization_id', $file->organization_id)->where('source_type', 'file_document')->where('entity_type', 'file')->where('entity_id', (string) $file->id);
    }

    private function file(): File
    {
        return Model::withoutEvents(function (): File {
            $organization = Organization::factory()->create();
            $user = User::factory()->create(['current_organization_id' => $organization->id]);
            $project = Project::factory()->create(['organization_id' => $organization->id]);
            return File::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id,
                'fileable_type' => $project->getMorphClass(), 'fileable_id' => $project->id,
                'name' => 'source.txt', 'original_name' => 'source.txt', 'path' => 'org-'.$organization->id.'/source.txt',
                'mime_type' => 'text/plain', 'size' => 16, 'disk' => 's3']);
        });
    }
}
