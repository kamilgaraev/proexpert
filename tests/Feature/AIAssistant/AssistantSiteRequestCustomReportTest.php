<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportReference;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportRenderer;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportSource;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Logging\LoggingService;
use App\Services\Modules\PackageCatalogService;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantSiteRequestCustomReportTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;

    private User $actor;

    private Project $firstProject;

    private Project $secondProject;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['cache.default' => 'array']);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->actor = $this->fixture->addMember(['ai-assistant' => ['ai_assistant.chat', 'ai_assistant.reports.generate', 'ai_assistant.analytics.view', 'ai_assistant.usage.view']],
            ['projects.view', 'site_requests.view', 'users.view', 'reports.view']);
        $this->firstProject = $this->project('Первый объект');
        $this->secondProject = $this->project('Второй объект');
        $this->actor->assignedProjects()->attach([$this->firstProject->id => ['is_active' => true, 'role' => 'member'],
            $this->secondProject->id => ['is_active' => true, 'role' => 'member']]);
        $this->actingAs($this->actor);
        self::assertSame(1, DB::transactionLevel());
        DB::commit();
        self::assertTrue(app(AuthorizationService::class)->canCurrent($this->actor, 'site_requests.view'));
        self::assertTrue(app(AuthorizationService::class)->canCurrent($this->actor, 'projects.view'));
        self::assertTrue(app(AssistantDataAccessPolicy::class)->canReadDomain($this->actor, $this->fixture->organization->id, 'reports'));
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() === 0) {
            DB::beginTransaction();
        }
        parent::tearDown();
    }

    public function test_two_sheet_workbook_preserves_request_filters_labels_and_organization_scope(): void
    {
        $overdue = $this->request($this->firstProject, ['title' => 'Материалы без ответственного', 'required_date' => '2026-10-20']);
        $undated = $this->request($this->secondProject, ['title' => 'Материалы без срока', 'required_date' => null, 'status' => 'pending']);
        $assigned = $this->request($this->secondProject, ['title' => 'Назначенные материалы', 'required_date' => '2026-10-19', 'assigned_to' => $this->actor->id]);
        foreach (['draft', 'completed', 'cancelled'] as $status) {
            $this->request($this->firstProject, ['status' => $status, 'required_date' => '2026-10-01']);
        }
        foreach (['equipment_request', 'personnel_request'] as $type) {
            $this->request($this->firstProject, ['request_type' => $type, 'required_date' => '2026-10-01']);
        }
        $this->request($this->project('SECRET недоступный объект'), ['title' => 'SECRET недоступная заявка', 'required_date' => '2026-10-01']);
        $foreign = Model::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->foreignOrganization->id, 'is_archived' => false]));
        $this->request($foreign, ['organization_id' => $this->fixture->foreignOrganization->id, 'user_id' => $this->fixture->foreignOwner->id,
            'title' => 'SECRET чужая заявка', 'required_date' => '2026-10-01']);
        $source = app(AssistantCustomReportSource::class)->resolve($this->actor, $this->fixture->organization->id, 'site_requests', 'site_request', null);
        foreach (['id', 'title', 'project_id', 'status', 'priority', 'request_type', 'required_date', 'assigned_to'] as $field) {
            self::assertArrayHasKey($field, $source['fields']);
        }
        self::assertArrayNotHasKey('estimated_cost', $source['fields']);
        self::assertArrayNotHasKey('contact_person_phone', $source['fields']);
        self::assertContains(['value' => 'approved', 'label' => 'Одобрена'], $source['fields']['status']['choices']);
        self::assertContains(['value' => 'medium', 'label' => 'Средний'], $source['fields']['priority']['choices']);
        $base = [['field' => 'request_type', 'operator' => 'eq', 'value' => 'material_request']];
        foreach (['draft', 'completed', 'cancelled'] as $status) {
            $base[] = ['field' => 'status', 'operator' => 'neq', 'value' => $status];
        }
        $plan = ['title' => 'Контроль заявок', 'format' => 'xlsx', 'sections' => [
            $this->section('Просроченные', [...$base, ['field' => 'required_date', 'operator' => 'lt', 'value' => '2026-10-21']]),
            $this->section('Без ответственного', [...$base, ['field' => 'assigned_to', 'operator' => 'is_null', 'value' => null]]),
        ]];
        $report = app(AssistantCustomReportBuilder::class)->build($plan, $this->actor, $this->fixture->organization->id);
        self::assertSame([$overdue->id, $assigned->id], array_column($report['sections'][0]['rows'], 0));
        self::assertSame([$overdue->id, $undated->id], array_column($report['sections'][1]['rows'], 0));
        self::assertSame([2, 2], array_column($report['sections'], 'source_row_count'));
        self::assertSame('Одобрена', $report['sections'][0]['rows'][0][3]);
        self::assertSame('Средний', $report['sections'][0]['rows'][0][4]);
        self::assertStringContainsString('Первый объект', $report['sections'][0]['rows'][0][2]);
        self::assertStringContainsString($this->actor->name, $report['sections'][0]['rows'][1][6]);
        self::assertStringNotContainsString('SECRET', json_encode($report['sections'], JSON_THROW_ON_ERROR));
        foreach ($report['source_refs'] as $ref) {
            self::assertTrue(app(AssistantCustomReportReference::class)->canReadReference($this->actor, $this->fixture->organization->id, $ref));
        }
        $stream = (new AssistantCustomReportRenderer)->render($report);
        try {
            $book = IOFactory::load(stream_get_meta_data($stream)['uri']);
            try {
                self::assertSame(2, $book->getSheetCount());
                self::assertSame((string) $overdue->id, (string) $book->getSheet(0)->getCell('A6')->getValue());
                self::assertSame((string) $undated->id, (string) $book->getSheet(1)->getCell('A7')->getValue());
                self::assertSame('Одобрена', $book->getSheet(0)->getCell('D6')->getValue());
                self::assertSame('2026-10-20', $book->getSheet(0)->getCell('F6')->getValue());
            } finally {
                $book->disconnectWorksheets();
            }
        } finally {
            fclose($stream);
        }
    }

    public function test_report_keeps_native_draft_visibility_when_drafts_are_requested(): void
    {
        $own = $this->request($this->firstProject, ['status' => 'draft']);
        $this->request($this->firstProject, ['status' => 'draft', 'user_id' => $this->fixture->owner->id, 'title' => 'SECRET чужой черновик']);
        $pending = $this->request($this->firstProject, ['status' => 'pending']);
        $report = app(AssistantCustomReportBuilder::class)->build(['title' => 'Все статусы', 'format' => 'xlsx',
            'sections' => [$this->section('Заявки', [])]], $this->actor, $this->fixture->organization->id);
        self::assertSame([$own->id, $pending->id], array_column($report['sections'][0]['rows'], 0));
        self::assertSame(['Черновик', 'Ожидает обработки'], array_column($report['sections'][0]['rows'], 3));
        self::assertStringNotContainsString('SECRET', json_encode($report['sections'], JSON_THROW_ON_ERROR));
    }

    public function test_exact_live_question_reaches_report_generation_and_returns_a_real_two_sheet_file(): void
    {
        config(['ai-assistant.enabled' => true, 'ai-assistant-credits.enforce' => true,
            'ai-assistant-credits.profiles.normal.max_calls' => 4]);
        $today = now('Europe/Moscow')->toDateString();
        $overdue = $this->request($this->firstProject, ['required_date' => now('Europe/Moscow')->subDay()->toDateString()]);
        $undated = $this->request($this->secondProject, ['required_date' => null, 'status' => 'pending']);
        $base = [['field' => 'request_type', 'operator' => 'eq', 'value' => 'material_request']];
        foreach (['draft', 'completed', 'cancelled'] as $status) {
            $base[] = ['field' => 'status', 'operator' => 'neq', 'value' => $status];
        }
        $report = ['title' => 'Контроль заявок', 'format' => 'xlsx', 'sections' => [
            $this->section('Просроченные', [...$base, ['field' => 'required_date', 'operator' => 'lt', 'value' => $today]]),
            $this->section('Без ответственного', [...$base, ['field' => 'assigned_to', 'operator' => 'is_null', 'value' => null]]),
        ]];
        $calls = [
            ['describe_custom_report_source', ['domain' => 'site_requests', 'entity_type' => 'site_request', 'project_id' => null]],
            ['describe_custom_report_source', ['domain' => 'site_requests', 'entity_type' => 'site_request', 'project_id' => null]],
            ['generate_custom_report', $report],
        ];
        $provider = $this->createMock(SiteRequestReportResponsesProvider::class);
        $provider->method('getModel')->willReturn('gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $provider->expects(self::exactly(4))->method('responses')->willReturnCallback(static function (array $input, array $options) use (&$calls): array {
            self::assertContains('generate_custom_report', array_column($options['tools'] ?? [], 'name'));
            if ($calls === []) {
                return ['content' => 'Отчёт сформирован.', 'output' => [], 'function_calls' => [], 'input_tokens' => 100,
                    'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
            }
            [$name, $arguments] = array_shift($calls);
            $id = 'site-request-report-'.(3 - count($calls));
            $call = ['type' => 'function_call', 'id' => $id, 'status' => 'completed', 'call_id' => $id, 'name' => $name,
                'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];

            return ['content' => '', 'output' => [$call], 'function_calls' => [$call], 'input_tokens' => 100,
                'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
        });
        $bytes = null;
        $files = $this->createMock(FileService::class);
        $files->expects(self::once())->method('putPrivate')->willReturnCallback(static function (string $path, $stream, string $mime, string $hash) use (&$bytes): CurrentStoredFile {
            $bytes = stream_get_contents($stream);
            self::assertStringStartsWith('PK', $bytes);
            self::assertSame(hash('sha256', $bytes), $hash);

            return new CurrentStoredFile($path, 'test-etag', strlen($bytes), $hash, $mime);
        });
        $this->app->instance(FileService::class, $files);
        $schema = app(AIToolRegistry::class)->getTool('generate_custom_report')->getParametersSchema();
        foreach ($report['sections'] as $section) {
            self::assertSame([], array_values(array_diff($schema['properties']['sections']['items']['required'], array_keys($section))));
        }
        $org = $this->fixture->organization;
        app(AICreditService::class)->grant($org, 100000, 'purchase', null, 'site-request-report-'.Str::uuid());
        $query = 'Собери Excel для контроля заявок на материалы по всей организации. На первом листе — незакрытые заявки со сроком раньше сегодняшнего дня; на втором — незакрытые заявки без ответственного, в том числе без срока. Нужны идентификатор заявки, название, проект, статус, приоритет, требуемая дата и ответственный. Черновики, закрытые и отменённые заявки исключи, заявки на технику и персонал тоже. Если заявка подходит под оба условия, включи её на оба листа.';
        $payload = ['request_id' => (string) Str::uuid(), 'message' => $query, 'profile' => 'normal', 'allow_actions' => false,
            'context' => ['entity_refs' => [['type' => 'project', 'id' => (int) $this->firstProject->id]]]];
        $quote = app(AICreditService::class)->quote($org, $this->actor, $payload);
        $payload['quote_id'] = $quote['quote_id'];
        $technicalErrors = [];
        $logging = $this->createMock(LoggingService::class);
        $logging->method('technical')->willReturnCallback(static function (string $event, array $context, string $level) use (&$technicalErrors): void {
            if ($event === 'ai.tool.error') {
                $technicalErrors[] = $context;
            }
        });
        $this->app->instance(LoggingService::class, $logging);
        $service = $this->app->makeWith(AIAssistantService::class, ['llmProvider' => $provider, 'ragRetriever' => null]);
        $response = $service->ask($query, (int) $org->id, $this->actor, null, $payload);
        self::assertSame('verified', $response['message']['metadata']['validation_status'], json_encode([
            'answer' => $response['message']['content'] ?? null,
            'missing_data' => $response['message']['metadata']['missing_data'] ?? null,
            'tool_failures' => $response['message']['metadata']['telemetry']['tool_failures'] ?? null,
            'artifact_count' => count($response['message']['metadata']['artifacts'] ?? []),
            'needs_clarification' => $response['message']['metadata']['needs_clarification'] ?? null,
            'bytes' => is_string($bytes) ? strlen($bytes) : null,
            'technical_errors' => $technicalErrors,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertSame([], $technicalErrors);
        self::assertFalse($response['message']['metadata']['needs_clarification']);
        self::assertCount(1, $response['message']['metadata']['artifacts']);
        self::assertIsString($bytes);
        $temporary = tmpfile();
        self::assertIsResource($temporary);
        try {
            fwrite($temporary, $bytes);
            $book = IOFactory::load(stream_get_meta_data($temporary)['uri']);
            try {
                self::assertSame(2, $book->getSheetCount());
                self::assertSame((string) $overdue->id, (string) $book->getSheet(0)->getCell('A6')->getValue());
                self::assertSame((string) $overdue->id, (string) $book->getSheet(1)->getCell('A6')->getValue());
                self::assertSame((string) $undated->id, (string) $book->getSheet(1)->getCell('A7')->getValue());
                self::assertSame('Одобрена', $book->getSheet(0)->getCell('D6')->getValue());
            } finally {
                $book->disconnectWorksheets();
            }
        } finally {
            fclose($temporary);
        }
    }

    public function test_revoked_membership_denies_new_reads_and_previous_report_references(): void
    {
        $this->request($this->firstProject);
        $report = app(AssistantCustomReportBuilder::class)->build(['title' => 'Заявки', 'format' => 'xlsx',
            'sections' => [$this->section('Заявки', [])]], $this->actor, $this->fixture->organization->id);
        $ref = $report['source_refs'][0];
        self::assertTrue(app(AssistantCustomReportReference::class)->canReadReference($this->actor, $this->fixture->organization->id, $ref));
        $this->actor->organizations()->updateExistingPivot($this->fixture->organization->id, ['is_active' => false]);
        self::assertFalse(app(AssistantCustomReportReference::class)->canReadReference($this->actor, $this->fixture->organization->id, $ref));
        $this->expectException(AccessDeniedHttpException::class);
        app(AssistantCustomReportSource::class)->resolve($this->actor, $this->fixture->organization->id, 'site_requests', 'site_request', null);
    }

    private function project(string $name): Project
    {
        return Model::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id, 'name' => $name, 'is_archived' => false]));
    }

    private function request(Project $project, array $override = []): SiteRequest
    {
        return Model::withoutEvents(fn () => SiteRequest::query()->create(array_replace([
            'organization_id' => $this->fixture->organization->id, 'project_id' => $project->id, 'user_id' => $this->actor->id,
            'title' => 'Материалы', 'status' => 'approved', 'priority' => 'medium', 'request_type' => 'material_request',
        ], $override)));
    }

    private function section(string $title, array $filters): array
    {
        return ['title' => $title, 'domain' => 'site_requests', 'entity_type' => 'site_request', 'project_id' => null,
            'row_limit' => 1000,
            'columns' => ['id', 'title', 'project_id', 'status', 'priority', 'required_date', 'assigned_to'],
            'filters' => $filters, 'group_by' => [], 'metrics' => [], 'order_by' => [['field' => 'id', 'direction' => 'asc']]];
    }
}

interface SiteRequestReportResponsesProvider extends LLMProviderInterface
{
    public function responses(array $input, array $options = []): array;
}
