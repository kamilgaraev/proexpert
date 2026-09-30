<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagJobDispatcher;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentImport;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentImportItem;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentImportService;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantLegalBusinessMutationTest extends TestCase
{
    public function test_bulk_failure_queues_only_actual_item_and_inherited_project_after_commit(): void
    {
        [$fixture,$project,$item,$unrelated] = $this->fixture();
        Queue::fake([IndexRagSourceJob::class]);
        DB::beginTransaction();
        app(ExecutiveDocumentImportService::class)->fail($item->id,1,new RuntimeException('private-provider-secret'));
        self::assertSame('failed',$item->fresh()->status);
        self::assertSame('queued',$unrelated->fresh()->status);
        Queue::assertNotPushed(IndexRagSourceJob::class);
        DB::commit();
        $runs = $this->runs($fixture->organization->id)->get();
        self::assertCount(1,$runs);
        self::assertSame((string)$item->id,$runs[0]->entity_id);
        self::assertSame($project->id,$runs[0]->project_id);
        self::assertStringNotContainsString('private-provider-secret',json_encode($item->fresh()->errors,JSON_THROW_ON_ERROR));
        Queue::assertPushed(IndexRagSourceJob::class,static fn (IndexRagSourceJob $job): bool => $job->runId === $runs[0]->id);
    }

    public function test_business_rollback_restores_item_and_discards_durable_runs_and_dispatch(): void
    {
        [$fixture,,$item] = $this->fixture();
        Queue::fake([IndexRagSourceJob::class]);
        DB::beginTransaction();
        app(ExecutiveDocumentImportService::class)->fail($item->id,1,new RuntimeException('failed'));
        self::assertSame('failed',$item->fresh()->status);
        DB::rollBack();
        self::assertSame('queued',$item->fresh()->status);
        self::assertSame(0,$this->runs($fixture->organization->id)->count());
        Queue::assertNotPushed(IndexRagSourceJob::class);
    }

    public function test_postgres_queue_failure_rolls_back_business_change_and_intent(): void
    {
        [$fixture,,$item] = $this->fixture();
        Queue::fake([IndexRagSourceJob::class]);
        $coordinator = new LegalMutationSqlFailureCoordinator(app(RagIndexer::class),app(RagJobDispatcher::class));
        $coordinator->failItemId = (string)$item->id;
        $this->app->instance(RagIndexingCoordinator::class,$coordinator);
        DB::beginTransaction();
        try {
            app(ExecutiveDocumentImportService::class)->fail($item->id,1,new RuntimeException('failed'));
            self::fail('Expected a database error while persisting the RAG intent.');
        } catch (QueryException $exception) {
            self::assertSame('22012', $coordinator->sqlState);
        }
        self::assertGreaterThan(0,$coordinator->failures);
        self::assertSame(1,(int)DB::selectOne('SELECT 1 AS one')->one);
        DB::commit();
        self::assertSame('queued',$item->fresh()->status);
        self::assertSame(0,$this->runs($fixture->organization->id)->count());
        Queue::assertNotPushed(IndexRagSourceJob::class);
    }

    private function runs(int $organizationId): Builder
    {
        return RagIndexRun::query()->where('organization_id',$organizationId)->where('source_type','executive_business')->where('entity_type','executive_import_item');
    }

    private function fixture(): array
    {
        return Model::withoutEvents(static function (): array {
            $fixture = AssistantRealAuthorizationFixture::create();
            $project = Project::factory()->create(['organization_id'=>$fixture->organization->id]);
            $set = ExecutiveDocumentSet::query()->create(['organization_id'=>$fixture->organization->id,'project_id'=>$project->id,
                'created_by'=>$fixture->owner->id,'set_number'=>'AI-MUTATION','title'=>'Комплект','status'=>'draft']);
            $batch = ExecutiveDocumentImport::query()->create(['organization_id'=>$fixture->organization->id,'document_set_id'=>$set->id,
                'created_by'=>$fixture->owner->id,'operation_key'=>'ai-mutation','manifest_hash'=>str_repeat('a',64)]);
            $data = ['import_id'=>$batch->id,'original_name'=>'Документ.pdf','size'=>12,'content_hash'=>str_repeat('b',64),'status'=>'queued','attempt'=>1];
            $item = ExecutiveDocumentImportItem::query()->create($data + ['client_key'=>'selected']);
            $unrelated = ExecutiveDocumentImportItem::query()->create($data + ['client_key'=>'unrelated']);
            return [$fixture,$project,$item,$unrelated];
        });
    }
}

final class LegalMutationSqlFailureCoordinator extends RagIndexingCoordinator
{
    public string $failItemId = '';
    public int $failures = 0;
    public ?string $sqlState = null;

    public function queueEntity(int $organizationId,?int $projectId,string $sourceType,string $entityType,string|int $entityId): RagIndexRun
    {
        if ($entityType === 'executive_import_item' && (string)$entityId === $this->failItemId) {
            $this->failures++;
            try { DB::select('SELECT 1 / 0'); }
            catch (QueryException $exception) { $this->sqlState = $exception->errorInfo[0] ?? null; throw $exception; }
        }
        return parent::queueEntity($organizationId,$projectId,$sourceType,$entityType,$entityId);
    }
}
