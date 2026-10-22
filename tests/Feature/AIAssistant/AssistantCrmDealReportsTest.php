<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportReference;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportSource;
use App\BusinessModules\Features\Crm\Models\CrmCompany;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\BusinessModules\Features\Crm\Models\CrmPipeline;
use App\BusinessModules\Features\Crm\Models\CrmPipelineStage;
use App\BusinessModules\Features\Crm\Services\CrmDealReportReadService;
use App\Models\Project;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Modules\PackageCatalogService;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantCrmDealReportsTest extends TestCase
{
    private const QUERY = 'Собери CSV для контроля открытых сделок CRM по всей организации: название сделки, компания, этап, ответственный, проект и ожидаемая дата закрытия. Сделки без проекта тоже включи. Не ограничивайся выбранным проектом.';

    private AssistantRealAuthorizationFixture $fixture;

    private Project $uiProject;

    private CrmCompany $company;

    private CrmPipeline $pipeline;

    private CrmPipelineStage $stage;

    private array $dealIds;

    private User $assignee;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['cache.default' => 'array']);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        Model::withoutEvents(function (): void {
            $organizationId = $this->fixture->organization->id;
            $this->assignee = $this->fixture->addMember();
            $this->uiProject = Project::factory()->create(['organization_id' => $organizationId, 'name' => 'Первый объект', 'is_archived' => false]);
            $this->company = CrmCompany::query()->create(['organization_id' => $organizationId, 'name' => 'Компания заказчика']);
            $this->pipeline = CrmPipeline::query()->create(['organization_id' => $organizationId, 'code' => 'report-test', 'label' => 'Продажи', 'entity_type' => 'deal', 'is_active' => true]);
            $this->stage = CrmPipelineStage::query()->create(['pipeline_id' => $this->pipeline->id, 'code' => 'new', 'label' => 'Новая сделка']);
            $base = ['organization_id' => $organizationId, 'company_id' => $this->company->id, 'pipeline_id' => $this->pipeline->id,
                'stage_id' => $this->stage->id, 'pipeline_code' => 'report-test', 'stage_code' => 'new', 'status' => 'open'];
            $first = CrmDeal::query()->create([...$base, 'title' => 'A — Сделка проекта', 'project_id' => $this->uiProject->id, 'owner_user_id' => $this->assignee->id, 'expected_close_at' => '2026-10-30']);
            $second = CrmDeal::query()->create([...$base, 'title' => 'B — Сделка без проекта']);
            $this->dealIds = [$first->id, $second->id];
            CrmDeal::query()->create([...$base, 'title' => 'WON не включать', 'status' => 'won']);
            $foreignCompany = CrmCompany::query()->create(['organization_id' => $this->fixture->foreignOrganization->id, 'name' => 'SECRET компания']);
            CrmDeal::query()->create(['organization_id' => $this->fixture->foreignOrganization->id, 'company_id' => $foreignCompany->id, 'title' => 'SECRET чужая сделка', 'status' => 'open']);
        });
        $this->actingAs($this->fixture->owner);
        self::assertSame(1, DB::transactionLevel());
        DB::commit();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() === 0) {
            DB::beginTransaction();
        }
        parent::tearDown();
    }

    public function test_crm_report_source_exposes_native_company_owner_and_stage_labels(): void
    {
        $source = app(AssistantCustomReportSource::class)->resolve($this->fixture->owner, $this->fixture->organization->id, 'crm', 'crm_deal', null);
        foreach (['company_name' => 'Компания', 'owner_name' => 'Ответственный', 'stage_label' => 'Этап сделки'] as $field => $label) {
            self::assertArrayHasKey($field, $source['fields']);
            self::assertSame('string', $source['fields'][$field]['type']);
            self::assertSame($label, $source['fields'][$field]['label']);
        }
        $rows = $source['query']->where('status', 'open')->orderBy('title')->get()->map(static fn ($row): array => array_intersect_key($row->getRawOriginal(), array_flip(['title', 'company_name', 'owner_name', 'stage_label'])))->all();
        self::assertSame([
            ['title' => 'A — Сделка проекта', 'company_name' => 'Компания заказчика', 'owner_name' => $this->assignee->name, 'stage_label' => 'Новая сделка'],
            ['title' => 'B — Сделка без проекта', 'company_name' => 'Компания заказчика', 'owner_name' => null, 'stage_label' => 'Новая сделка'],
        ], $rows);
    }

    public function test_crm_labels_follow_deal_permission_without_requiring_independent_user_or_company_access(): void
    {
        $actor = Model::withoutEvents(fn () => $this->fixture->addMember(['crm' => ['crm.view', 'crm.deals.view']]));
        CrmDeal::query()->whereKey($this->dealIds[0])->update(['project_id' => null]);
        $source = app(AssistantCustomReportSource::class)->resolve($actor, $this->fixture->organization->id, 'crm', 'crm_deal', null);
        $row = $source['query']->whereKey($this->dealIds[0])->firstOrFail();
        self::assertSame('Компания заказчика', $row->getRawOriginal('company_name'));
        self::assertSame($this->assignee->name, $row->getRawOriginal('owner_name'));
        self::assertSame('Новая сделка', $row->getRawOriginal('stage_label'));
        self::assertArrayNotHasKey('amount', $source['fields']);
        self::assertArrayNotHasKey('currency', $source['fields']);
        foreach ([['people', 'user'], ['crm', 'crm_company']] as [$domain, $entity]) {
            try {
                app(AssistantCustomReportSource::class)->resolve($actor, $this->fixture->organization->id, $domain, $entity, null);
                self::fail('Independent related-entity listing must remain denied');
            } catch (AccessDeniedHttpException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_crm_labels_never_follow_foreign_or_mismatched_relations_and_preserve_legacy_codes(): void
    {
        $foreign = $this->fixture->foreignOrganization->id;
        [$foreignCompany, $foreignOwner, $foreignPipeline, $foreignStage] = Model::withoutEvents(function () use ($foreign): array {
            $company = CrmCompany::query()->create(['organization_id' => $foreign, 'name' => 'SECRET foreign company']);
            $owner = User::factory()->create(['name' => 'SECRET foreign user']);
            $pipeline = CrmPipeline::query()->create(['organization_id' => $foreign, 'code' => 'foreign', 'label' => 'SECRET pipeline', 'entity_type' => 'deal']);
            $stage = CrmPipelineStage::query()->create(['pipeline_id' => $pipeline->id, 'code' => 'foreign', 'label' => 'SECRET stage']);

            return [$company, $owner, $pipeline, $stage];
        });
        CrmDeal::query()->whereKey($this->dealIds[0])->update(['company_id' => $foreignCompany->id, 'owner_user_id' => $foreignOwner->id,
            'pipeline_id' => $foreignPipeline->id, 'stage_id' => $foreignStage->id, 'stage_code' => 'legacy-safe-code']);
        $source = app(AssistantCustomReportSource::class)->resolve($this->fixture->owner, $this->fixture->organization->id, 'crm', 'crm_deal', null);
        $row = (clone $source['query'])->whereKey($this->dealIds[0])->firstOrFail();
        self::assertNull($row->getRawOriginal('company_name'));
        self::assertNull($row->getRawOriginal('owner_name'));
        self::assertSame('legacy-safe-code', $row->getRawOriginal('stage_label'));
        self::assertStringNotContainsString('SECRET', json_encode($row->getRawOriginal(), JSON_THROW_ON_ERROR));
        CrmDeal::query()->whereKey($this->dealIds[0])->update(['pipeline_id' => $this->pipeline->id]);
        self::assertSame('legacy-safe-code', (clone $source['query'])->whereKey($this->dealIds[0])->firstOrFail()->getRawOriginal('stage_label'));
        CrmDeal::query()->whereKey($this->dealIds[0])->update(['pipeline_id' => null, 'stage_id' => null]);
        self::assertSame('legacy-safe-code', (clone $source['query'])->whereKey($this->dealIds[0])->firstOrFail()->getRawOriginal('stage_label'));
        $this->company->deleteQuietly();
        self::assertNull((clone $source['query'])->whereKey($this->dealIds[1])->firstOrFail()->getRawOriginal('company_name'));
    }

    public function test_crm_label_references_recheck_membership_company_ownership_and_stage_links(): void
    {
        $report = app(AssistantCustomReportBuilder::class)->build($this->specification(), $this->fixture->owner, $this->fixture->organization->id);
        $reference = $report['source_refs'][0];
        $guard = app(AssistantCustomReportReference::class);
        $check = fn (array $ref): bool => $guard->canReadReference($this->fixture->owner, $this->fixture->organization->id, $ref);
        self::assertTrue($check($reference));
        $reordered = $reference;
        $reordered['_report_scope']['crm_label_links'] = array_map(static fn (array $link): array => array_reverse($link, true), $reordered['_report_scope']['crm_label_links']);
        self::assertTrue($check($reordered));
        $this->company->updateQuietly(['organization_id' => $this->fixture->foreignOrganization->id]);
        self::assertFalse($check($reference));
        $this->company->updateQuietly(['organization_id' => $this->fixture->organization->id]);
        self::assertTrue($check($reference));
        DB::table('organization_user')->where('organization_id', $this->fixture->organization->id)->where('user_id', $this->assignee->id)->update(['is_active' => false]);
        self::assertFalse($check($reference));
        DB::table('organization_user')->where('organization_id', $this->fixture->organization->id)->where('user_id', $this->assignee->id)->update(['is_active' => true]);
        self::assertTrue($check($reference));
        CrmDeal::query()->whereKey($this->dealIds[0])->update(['stage_id' => null]);
        self::assertFalse($check($reference));
    }

    public function test_crm_virtual_label_filters_are_applied_before_output_limiting(): void
    {
        $specification = $this->specification();
        $specification['sections'][0]['filters'][] = ['field' => 'company_name', 'operator' => 'contains', 'value' => 'заказчика'];
        $specification['sections'][0]['row_limit'] = 1;
        $report = app(AssistantCustomReportBuilder::class)->build($specification, $this->fixture->owner, $this->fixture->organization->id);
        self::assertSame(2, $report['sections'][0]['source_row_count']);
        self::assertCount(1, $report['sections'][0]['rows']);
        self::assertSame('A — Сделка проекта', $report['sections'][0]['rows'][0][0]);
        self::assertSame('Компания заказчика', $report['sections'][0]['rows'][0][1]);
        self::assertTrue(app(AssistantCustomReportReference::class)->canReadReference($this->fixture->owner, $this->fixture->organization->id, $report['source_refs'][0]));
    }

    public function test_crm_report_requires_real_deal_view_permission(): void
    {
        $actor = Model::withoutEvents(fn () => $this->fixture->addMember(['crm' => ['crm.view']]));
        $this->expectException(AccessDeniedHttpException::class);
        app(AssistantCustomReportSource::class)->resolve($actor, $this->fixture->organization->id, 'crm', 'crm_deal', null);
    }

    public function test_public_crm_report_reader_checks_actor_organization_permission_and_project_scope_itself(): void
    {
        $reader = app(CrmDealReportReadService::class);
        $organizationId = $this->fixture->organization->id;
        $denied = Model::withoutEvents(fn () => $this->fixture->addMember(['crm' => ['crm.view']]));
        foreach ([[$denied, $organizationId], [$this->fixture->owner, $this->fixture->foreignOrganization->id]] as [$actor, $org]) {
            try {
                $reader->query($actor, $org, CrmDeal::query());
                self::fail('The public CRM reader must enforce access independently of assistant/controller');
            } catch (ModelNotFoundException) {
                self::assertTrue(true);
            }
        }
        $member = Model::withoutEvents(fn () => $this->fixture->addMember(['crm' => ['crm.view', 'crm.deals.view']]));
        $rows = $reader->query($member, $organizationId, CrmDeal::query())->where('status', 'open')->get();
        self::assertSame([$this->dealIds[1]], $rows->modelKeys());
        self::assertSame('Компания заказчика', $rows[0]->getRawOriginal('company_name'));
        foreach (['amount', 'currency', 'custom_fields', 'lost_reason'] as $field) {
            self::assertArrayNotHasKey($field, $rows[0]->getRawOriginal());
        }
        $foreignRestriction = CrmDeal::query()->where('organization_id', $this->fixture->foreignOrganization->id);
        self::assertSame(0, $reader->query($this->fixture->owner, $organizationId, $foreignRestriction)->count());
        DB::table('organization_user')->where('organization_id', $organizationId)->where('user_id', $member->id)->update(['is_active' => false]);
        $this->expectException(ModelNotFoundException::class);
        $reader->query($member, $organizationId, CrmDeal::query());
    }

    public function test_exact_crm_question_generates_real_csv_with_projectless_deals_and_native_labels(): void
    {
        config(['ai-assistant.enabled' => true, 'ai-assistant-credits.enforce' => true,
            'ai-assistant-credits.profiles.normal.max_calls' => 5]);
        $actor = $this->fixture->owner;
        $organization = $this->fixture->organization;
        app(AICreditService::class)->grant($organization, 100000, 'purchase', null, 'crm-report-'.Str::uuid());
        $bytes = null;
        $files = $this->createMock(FileService::class);
        $files->expects(self::once())->method('putPrivate')->willReturnCallback(static function (string $path, $stream, string $mime, string $hash) use (&$bytes): CurrentStoredFile {
            $bytes = stream_get_contents($stream);
            self::assertSame(hash('sha256', $bytes), $hash);

            return new CurrentStoredFile($path, 'test-etag', strlen($bytes), $hash, $mime);
        });
        $this->app->instance(FileService::class, $files);
        $report = $this->specification();
        $report['sections'][0]['project_id'] = (int) $this->uiProject->id;
        $calls = [['describe_custom_report_source', ['domain' => 'crm', 'entity_type' => 'crm_deal', 'project_id' => null]],
            ['generate_custom_report', $report]];
        $provider = $this->createMock(CrmDealReportResponsesProvider::class);
        $provider->method('getModel')->willReturn('gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $provider->method('responses')->willReturnCallback(static function (array $input, array $options) use (&$calls): array {
            if ($calls === []) {
                return ['content' => 'Отчёт сформирован.', 'output' => [], 'function_calls' => [], 'input_tokens' => 100,
                    'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
            }
            [$name, $arguments] = array_shift($calls);
            self::assertContains($name, array_column($options['tools'] ?? [], 'name'));
            $id = 'crm-report-'.count($calls);
            $call = ['type' => 'function_call', 'id' => $id, 'status' => 'completed', 'call_id' => $id, 'name' => $name,
                'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];

            return ['content' => '', 'output' => [$call], 'function_calls' => [$call], 'input_tokens' => 100,
                'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
        });
        $payload = ['request_id' => (string) Str::uuid(), 'message' => self::QUERY, 'profile' => 'normal', 'allow_actions' => false,
            'context' => ['entity_refs' => [['type' => 'project', 'id' => (int) $this->uiProject->id]]]];
        $payload['quote_id'] = app(AICreditService::class)->quote($organization, $actor, $payload)['quote_id'];
        $service = $this->app->makeWith(AIAssistantService::class, ['llmProvider' => $provider, 'ragRetriever' => null]);
        $response = $service->ask(self::QUERY, (int) $organization->id, $actor, null, $payload);
        $metadata = $response['message']['metadata'];
        self::assertFalse($metadata['needs_clarification']);
        self::assertSame('verified', $metadata['validation_status']);
        self::assertFalse($metadata['degraded_mode']);
        self::assertCount(1, $metadata['artifacts']);
        self::assertStringEndsWith('.csv', $metadata['artifacts'][0]['filename']);
        self::assertNotNull($bytes);
        self::assertStringNotContainsString('SECRET', $bytes);
        $primary = array_values(array_filter($metadata['source_refs'], static fn (array $ref): bool => ($ref['_report_scope']['entity_type'] ?? null) === 'crm_deal'));
        self::assertCount(1, $primary);
        self::assertNull($primary[0]['project_id']);
        self::assertSame('organization', $primary[0]['_report_scope']['plan']['scope']);
        self::assertSame($this->specification()['sections'][0]['filters'], $primary[0]['_report_scope']['plan']['filters']);
        $expectedIds = $this->dealIds;
        $actualIds = $primary[0]['_report_ids'];
        sort($expectedIds, SORT_STRING);
        sort($actualIds, SORT_STRING);
        self::assertSame($expectedIds, $actualIds);
        foreach ($metadata['source_refs'] as $ref) {
            self::assertTrue(app(AssistantCustomReportReference::class)->canReadReference($actor, $organization->id, $ref));
        }
        $stream = fopen('php://temp', 'w+b');
        self::assertNotFalse($stream);
        try {
            fwrite($stream, $bytes);
            rewind($stream);
            self::assertSame("\xEF\xBB\xBF", fread($stream, 3));
            self::assertSame(['Название', 'Компания', 'Этап сделки', 'Ответственный', 'Идентификатор проекта', 'Плановое закрытие'], fgetcsv($stream, null, ';', '"', ''));
            $rows = [];
            while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
                $rows[] = $row;
            }
            self::assertSame([
                ['A — Сделка проекта', 'Компания заказчика', 'Новая сделка', $this->assignee->name, '#'.$this->uiProject->id.' — Первый объект', '2026-10-30'],
                ['B — Сделка без проекта', 'Компания заказчика', 'Новая сделка', '', '', ''],
            ], $rows);
        } finally {
            fclose($stream);
        }
    }

    private function specification(): array
    {
        return ['title' => 'Открытые сделки CRM', 'format' => 'csv', 'sections' => [[
            'title' => 'Сделки', 'scope' => 'organization', 'domain' => 'crm', 'entity_type' => 'crm_deal', 'project_id' => null,
            'columns' => ['title', 'company_name', 'stage_label', 'owner_name', 'project_id', 'expected_close_at'],
            'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'open']],
            'group_by' => [], 'metrics' => [], 'order_by' => [['field' => 'title', 'direction' => 'asc']], 'row_limit' => 1000,
        ]]];
    }
}

interface CrmDealReportResponsesProvider extends LLMProviderInterface
{
    public function responses(array $input, array $options = []): array;
}
