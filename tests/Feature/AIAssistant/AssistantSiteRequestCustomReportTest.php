<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
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
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\IsolatedPostgresTestDatabase;
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
        $this->beforeApplicationDestroyed(static function (): void {
            self::assertFalse(Schema::hasTable('site_requests'));
            self::assertFalse(Schema::hasTable('organizations'));
            self::assertFalse(Schema::hasTable('users'));
        });
        self::assertSame(0, DB::transactionLevel());
        self::assertTrue(app(AuthorizationService::class)->canCurrent($this->actor, 'site_requests.view'));
        self::assertTrue(app(AuthorizationService::class)->canCurrent($this->actor, 'projects.view'));
        self::assertTrue(app(AssistantDataAccessPolicy::class)->canReadDomain($this->actor, $this->fixture->organization->id, 'reports'));
    }

    protected function refreshTestDatabase(): void
    {
        IsolatedPostgresTestDatabase::assertSafeConfiguration(DB::connection()->getConfig());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-f0-9]{24}_testing$/D', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->app[Kernel::class]->setArtisan(null);
        RefreshDatabaseState::$migrated = true;
        $this->beforeApplicationDestroyed(function (): void {
            try {
                DB::statement('DROP SCHEMA public CASCADE');
                DB::statement('CREATE SCHEMA public');
                DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
                DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            } finally {
                RefreshDatabaseState::$migrated = false;
            }
        });
    }

    public function test_request_type_descriptor_and_filters_use_canonical_values_for_legacy_rows(): void
    {
        $source = app(AssistantCustomReportSource::class)->resolve($this->actor, $this->fixture->organization->id, 'site_requests', 'site_request', null);
        $enum = \App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum::class;
        self::assertArrayHasKey('choices', $source['fields']['request_type']);
        self::assertSame(array_map(static fn ($type): array => ['value' => $type->value, 'label' => $type->label()], $enum::cases()), $source['fields']['request_type']['choices']);
        $canonical = $this->request($this->firstProject);
        $ids = ['material_request' => [$canonical->id]];
        foreach (['material', 'personnel', 'equipment', 'info', 'issue'] as $legacy) {
            $request = $this->request($this->secondProject);
            DB::table('site_requests')->where('id', $request->id)->update(['request_type' => $legacy]);
            $type = $enum::fromStoredValue($legacy)->value;
            $ids[$type][] = $request->id;
        }
        $unknown = $this->request($this->firstProject);
        DB::table('site_requests')->where('id', $unknown->id)->update(['request_type' => 'future_type']);
        $foreignProject = Model::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->foreignOrganization->id, 'is_archived' => false]));
        $this->request($foreignProject, ['user_id' => $this->fixture->foreignOwner->id, 'title' => 'SECRET другая организация']);
        $private = $this->project('SECRET неназначенный проект');
        $this->request($private, ['title' => 'SECRET неназначенная заявка']);
        $sections = [];
        foreach (['eq' => 'material_request', 'neq' => 'material_request', 'in' => ['material_request', 'personnel_request']] as $operator => $value) {
            $section = $this->section($operator, [['field' => 'request_type', 'operator' => $operator, 'value' => $value]]);
            $section['columns'] = ['id', 'request_type'];
            $sections[] = $section;
        }
        $report = app(AssistantCustomReportBuilder::class)->build(['title' => 'Типы заявок', 'format' => 'xlsx', 'sections' => $sections], $this->actor, $this->fixture->organization->id);
        self::assertSame($ids['material_request'], array_column($report['sections'][0]['rows'], 0));
        self::assertSame(array_merge($ids['personnel_request'], $ids['equipment_request'], $ids['info_request'], $ids['issue_report'], [$unknown->id]), array_column($report['sections'][1]['rows'], 0));
        self::assertSame(array_merge($ids['material_request'], $ids['personnel_request']), array_column($report['sections'][2]['rows'], 0));
        self::assertStringNotContainsString('SECRET', json_encode($report['sections'], JSON_THROW_ON_ERROR));
        $reference = $report['source_refs'][0];
        self::assertTrue(app(AssistantCustomReportReference::class)->canReadReference($this->actor, $this->fixture->organization->id, $reference));
        $this->actor->assignedProjects()->updateExistingPivot($this->secondProject->id, ['is_active' => false]);
        self::assertFalse(app(AssistantCustomReportReference::class)->canReadReference($this->actor, $this->fixture->organization->id, $reference));
    }

    public function test_request_type_filters_reject_legacy_input_instead_of_verifying_empty_results(): void
    {
        $this->request($this->firstProject);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(AssistantCustomReportBuilder::class)->build(['title' => 'Некорректный тип', 'format' => 'xlsx',
            'sections' => [$this->section('Материалы', [['field' => 'request_type', 'operator' => 'eq', 'value' => 'material']])]],
            $this->actor, $this->fixture->organization->id);
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

    public function test_workbook_labels_raw_legacy_types_and_preserves_unknown_codes(): void
    {
        $expected = [];
        foreach (['material', 'personnel', 'equipment', 'info', 'issue', 'other', 'future_type'] as $stored) {
            $request = $this->request($this->firstProject);
            DB::table('site_requests')->where('id', $request->id)->update(['request_type' => $stored]);
            $request->refresh();
            self::assertSame($stored, $request->getRawOriginal('request_type'));
            $expected[] = [$request->id, $stored === 'future_type' ? $stored : $request->request_type->label()];
        }
        $section = $this->section('Типы заявок', []);
        $section['columns'] = ['id', 'request_type'];
        $report = app(AssistantCustomReportBuilder::class)->build(['title' => 'Типы заявок', 'format' => 'xlsx',
            'sections' => [$section]], $this->actor, $this->fixture->organization->id);
        self::assertSame($expected, $report['sections'][0]['rows']);
        $stream = (new AssistantCustomReportRenderer)->render($report);
        try {
            $book = IOFactory::load(stream_get_meta_data($stream)['uri']);
            try {
                foreach ($expected as $offset => [$id, $label]) {
                    self::assertSame((string) $id, (string) $book->getSheet(0)->getCell('A'.($offset + 6))->getValue());
                    self::assertSame($label, $book->getSheet(0)->getCell('B'.($offset + 6))->getValue());
                }
            } finally {
                $book->disconnectWorksheets();
            }
        } finally {
            fclose($stream);
        }
    }

    public static function reportGenerationPositions(): array
    {
        return ['early' => [false], 'last_call' => [true], 'no_authorized_source' => [true, false],
            'early_text' => [false, true, true], 'first_text' => [false, true, false, true]];
    }

    #[DataProvider('reportGenerationPositions')]
    public function test_exact_live_question_reaches_report_generation_and_returns_a_real_two_sheet_file(bool $lastCall, bool $sourceAvailable = true, bool $earlyText = false, bool $firstText = false): void
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
        if ($lastCall) {
            array_splice($calls, 2, 0, [
                ['describe_custom_report_source', ['domain' => 'site_requests', 'entity_type' => 'site_request', 'project_id' => null]],
            ]);
        }
        if ($earlyText) {
            $calls[1] = ['text', []];
        }
        if ($firstText) {
            $calls = [['text', []], ['generate_custom_report', $report]];
        }
        if (! $sourceAvailable) {
            self::assertFalse(app(AuthorizationService::class)->canCurrent($this->actor, 'procurement.view'));
            $calls = array_fill(0, 4, ['assistant_domain_search', ['domain' => 'procurement', 'entity_type' => 'purchase_request', 'query' => '',
                'project_id' => null, 'limit' => 20, 'predicate' => null, 'cursor' => null, 'fields' => ['id', 'title']]]);
        }
        $provider = $this->createMock(SiteRequestReportResponsesProvider::class);
        $provider->method('getModel')->willReturn('gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $providerCalls = 0;
        $provider->expects(self::exactly($firstText ? 3 : 4))->method('responses')->willReturnCallback(static function (array $input, array $options) use (&$calls, &$providerCalls, $lastCall, $sourceAvailable, $earlyText, $firstText): array {
            self::assertContains('generate_custom_report', array_column($options['tools'] ?? [], 'name'));
            if (++$providerCalls === 1) {
                $sourceHints = [];
                foreach ($input as $item) {
                    $decoded = is_string($item['content'] ?? null) ? json_decode($item['content'], true) : null;
                    if (is_array($decoded) && ($decoded['kind'] ?? null) === 'custom_report_sources') {
                        $sourceHints = $decoded['sources'] ?? [];
                    }
                }
                if ($sourceAvailable) {
                    self::assertSame('site_requests', $sourceHints[0]['domain'] ?? null);
                    self::assertSame('site_request', $sourceHints[0]['entity_type']);
                    foreach (['id', 'title', 'project_id', 'status', 'priority', 'request_type', 'required_date', 'assigned_to'] as $field) {
                        self::assertArrayHasKey($field, $sourceHints[0]['fields']);
                    }
                } else {
                    self::assertSame([], $sourceHints);
                }
            }
            if (! $sourceAvailable || $calls === []) {
                self::assertArrayNotHasKey('tool_choice', $options);
            }
            if ($calls === []) {
                return ['content' => 'Отчёт сформирован.', 'output' => [], 'function_calls' => [], 'input_tokens' => 100,
                    'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
            }
            [$name, $arguments] = array_shift($calls);
            if (in_array($name, ['describe_custom_report_source', 'text'], true)) {
                self::assertArrayNotHasKey('tool_choice', $options);
            }
            if ($name === 'text') {
                return ['content' => 'Отчёт сформирован.', 'output' => [], 'function_calls' => [], 'input_tokens' => 100,
                    'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
            }
            if (($earlyText || $firstText) && $name === 'generate_custom_report') {
                self::assertSame('generate_custom_report', $options['tool_choice']['function']['name'] ?? null);
            }
            if ($lastCall && $name === 'generate_custom_report'
                && ($options['tool_choice']['function']['name'] ?? null) !== 'generate_custom_report') {
                $name = 'assistant_domain_search';
                $arguments = ['domain' => 'site_requests', 'entity_type' => 'site_request', 'query' => '',
                    'project_id' => null, 'limit' => 20, 'predicate' => null, 'cursor' => null, 'fields' => ['id', 'title']];
            }
            $id = 'site-request-report-'.(3 - count($calls));
            $call = ['type' => 'function_call', 'id' => $id, 'status' => 'completed', 'call_id' => $id, 'name' => $name,
                'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];

            return ['content' => '', 'output' => [$call], 'function_calls' => [$call], 'input_tokens' => 100,
                'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
        });
        $bytes = null;
        $files = $this->createMock(FileService::class);
        $files->expects($sourceAvailable ? self::once() : self::never())->method('putPrivate')->willReturnCallback(static function (string $path, $stream, string $mime, string $hash) use (&$bytes): CurrentStoredFile {
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
        $query = $sourceAvailable
            ? 'Собери Excel для контроля заявок на материалы по всей организации. На первом листе — незакрытые заявки со сроком раньше сегодняшнего дня; на втором — незакрытые заявки без ответственного, в том числе без срока. Нужны идентификатор заявки, название, проект, статус, приоритет, требуемая дата и ответственный. Черновики, закрытые и отменённые заявки исключи, заявки на технику и персонал тоже. Если заявка подходит под оба условия, включи её на оба листа.'
            : 'Собери Excel по закупочным заявкам всей организации.';
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
        if (! $sourceAvailable) {
            self::assertSame(trans_message('ai_custom_reports.missing_file'), $response['message']['content']);
            self::assertSame('unverified', $response['message']['metadata']['validation_status']);
            self::assertSame([], $response['message']['metadata']['artifacts']);
            self::assertFalse($response['message']['metadata']['needs_clarification']);
            self::assertTrue($response['message']['metadata']['degraded_mode']);
            self::assertSame('access_denied', $response['message']['metadata']['outcome'], json_encode([
                'tool_failures' => $response['message']['metadata']['telemetry']['tool_failures'] ?? null,
                'missing_data' => $response['message']['metadata']['missing_data'] ?? null,
                'tool_evidence' => $response['message']['metadata']['tool_evidence'] ?? null,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            self::assertTrue($response['message']['metadata']['access_denied']);
            self::assertSame([], $technicalErrors);
            self::assertNull($bytes);

            return;
        }
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

    public function test_main_loop_preserves_an_authorized_pdf_from_an_existing_report_generator(): void
    {
        $this->actor = $this->fixture->addMember(['ai-assistant' => ['ai_assistant.chat', 'ai_assistant.reports.generate', 'ai_assistant.analytics.view', 'ai_assistant.usage.view']],
            ['projects.view', 'warehouse.view', 'finance.view', 'reports.view']);
        $this->actor->assignedProjects()->attach($this->firstProject->id, ['is_active' => true, 'role' => 'member']);
        $this->actingAs($this->actor);
        Model::withoutEvents(fn () => \App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse::query()->create([
            'organization_id' => $this->fixture->organization->id, 'project_id' => $this->firstProject->id,
            'name' => 'Склад PDF', 'code' => 'PDF-FIXTURE', 'warehouse_type' => 'project', 'is_active' => true,
        ]));
        $access = app(\App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportAccessService::class);
        $refs = $access->scopeDomainReferences($this->actor, (int) $this->fixture->organization->id, ['warehouse', 'finance'], null);
        self::assertNotEmpty($refs);
        $access->assertReferences($this->actor, (int) $this->fixture->organization->id, $refs, ['warehouse', 'finance']);
        self::assertTrue(app(\App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker::class)
            ->canExecuteTool($this->actor, 'generate_warehouse_stock_report', []));
        $pdf = new \Dompdf\Dompdf;
        $pdf->loadHtml('<html><body>Warehouse stock fixture</body></html>');
        $pdf->render();
        $bytes = $pdf->output();
        self::assertIsString($bytes);
        $reports = $this->createMock(\App\Services\Report\ReportService::class);
        $reports->expects(self::once())->method('getWarehouseStockReport')->willReturn(
            new \Symfony\Component\HttpFoundation\StreamedResponse(static function () use ($bytes): void {
                echo $bytes;
            })
        );
        $this->app->instance(\App\Services\Report\ReportService::class, $reports);
        $files = $this->createMock(FileService::class);
        $files->expects(self::once())->method('putPrivate')->willReturnCallback(static function (string $path, $stream, string $mime, string $hash) use ($bytes): CurrentStoredFile {
            self::assertSame($bytes, stream_get_contents($stream));
            self::assertSame('application/pdf', $mime);
            self::assertSame(hash('sha256', $bytes), $hash);

            return new CurrentStoredFile($path, 'pdf-etag', strlen($bytes), $hash, $mime);
        });
        $this->app->instance(FileService::class, $files);
        app(AIToolRegistry::class)->registerTool(new \App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateWarehouseStockReportTool(
            $reports, new \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantGeneratedReportStorage(new \App\Services\Storage\PersonalFileService($files))
        ));
        $reportErrors = [];
        \Illuminate\Support\Facades\Log::listen(static function ($event) use (&$reportErrors): void {
            if ($event->level === 'error') {
                $reportErrors[] = ['message' => $event->message, 'trace' => $event->context['trace'] ?? null];
            }
        });
        $provider = $this->createMock(SiteRequestReportResponsesProvider::class);
        $provider->method('getModel')->willReturn('gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $calls = 0;
        $provider->expects(self::exactly(2))->method('responses')->willReturnCallback(static function (array $input, array $options) use (&$calls): array {
            self::assertArrayNotHasKey('tool_choice', $options);
            $call = ['type' => 'function_call', 'id' => 'legacy-stock-report', 'status' => 'completed', 'call_id' => 'legacy-stock-report',
                'name' => 'generate_warehouse_stock_report', 'arguments' => '{}'];
            $first = ++$calls === 1;

            return ['content' => $first ? '' : 'Отчёт сформирован.', 'output' => $first ? [$call] : [], 'function_calls' => $first ? [$call] : [],
                'input_tokens' => 100, 'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
        });
        $org = $this->fixture->organization;
        app(AICreditService::class)->grant($org, 100000, 'purchase', null, 'legacy-pdf-'.Str::uuid());
        $query = 'Сформируй PDF-отчёт по остаткам склада с колонками материал и количество.';
        $payload = ['request_id' => (string) Str::uuid(), 'message' => $query, 'profile' => 'normal', 'allow_actions' => false];
        $payload['quote_id'] = app(AICreditService::class)->quote($org, $this->actor, $payload)['quote_id'];
        $service = $this->app->makeWith(AIAssistantService::class, ['llmProvider' => $provider, 'ragRetriever' => null]);
        $response = $service->ask($query, (int) $org->id, $this->actor, null, $payload);
        $metadata = $response['message']['metadata'];
        self::assertSame('generate_report', $metadata['telemetry']['request_understanding']['primary_intent']);
        self::assertSame('pdf', $metadata['telemetry']['request_understanding']['output_format']);
        self::assertCount(1, $metadata['artifacts'], json_encode(['content' => $response['message']['content'], 'metadata' => $metadata, 'report_errors' => $reportErrors], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertSame('generate_warehouse_stock_report', $metadata['artifacts'][0]['source_tool']);
        self::assertSame('pdf', $metadata['artifacts'][0]['type']);
        self::assertStringStartsWith('/api/v1/ai-assistant/reports/', $metadata['artifacts'][0]['url']);
        self::assertNotSame(trans_message('ai_custom_reports.missing_file'), $response['message']['content']);
        self::assertSame('partial', $metadata['validation_status']);
        self::assertNotEmpty($metadata['source_refs']);
        foreach ($metadata['source_refs'] as $ref) {
            self::assertTrue(app(AssistantDataAccessPolicy::class)->canReadReference($this->actor, (int) $org->id, $ref));
        }
        self::assertFalse($metadata['degraded_mode']);
    }

    public function test_file_request_preserves_a_fresh_empty_named_project_clarification(): void
    {
        $provider = $this->createMock(SiteRequestReportResponsesProvider::class);
        $provider->method('getModel')->willReturn('gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $calls = 0;
        $provider->expects(self::exactly(4))->method('responses')->willReturnCallback(static function (array $input, array $options) use (&$calls): array {
            $call = ['type' => 'function_call', 'id' => 'missing-project-report', 'status' => 'completed', 'call_id' => 'missing-project-report',
                'name' => 'search_projects', 'arguments' => json_encode(['query' => 'Несуществующий объект', 'limit' => 10], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
            $first = ++$calls === 1;

            return ['content' => $first ? '' : 'Уточните название проекта.', 'output' => $first ? [$call] : [], 'function_calls' => $first ? [$call] : [],
                'input_tokens' => 100, 'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
        });
        $files = $this->createMock(FileService::class);
        $files->expects(self::never())->method('putPrivate');
        $this->app->instance(FileService::class, $files);
        $org = $this->fixture->organization;
        app(AICreditService::class)->grant($org, 100000, 'purchase', null, 'missing-project-report-'.Str::uuid());
        $query = 'В проекте «Несуществующий объект» сделай CSV с полями название и статус.';
        $payload = ['request_id' => (string) Str::uuid(), 'message' => $query, 'profile' => 'normal', 'allow_actions' => false];
        $payload['quote_id'] = app(AICreditService::class)->quote($org, $this->actor, $payload)['quote_id'];
        $service = $this->app->makeWith(AIAssistantService::class, ['llmProvider' => $provider, 'ragRetriever' => null]);
        $response = $service->ask($query, (int) $org->id, $this->actor, null, $payload);
        $metadata = $response['message']['metadata'];
        self::assertSame('generate_report', $metadata['telemetry']['request_understanding']['primary_intent']);
        self::assertSame('csv', $metadata['telemetry']['request_understanding']['output_format']);
        self::assertSame(trans_message('ai_assistant.project_search_no_matches'), $response['message']['content']);
        self::assertTrue($metadata['needs_clarification']);
        self::assertSame('partial', $metadata['validation_status']);
        self::assertSame([], $metadata['artifacts']);
    }

    public function test_file_request_preserves_the_generator_current_project_clarification(): void
    {
        $this->actor = $this->fixture->owner;
        $this->actingAs($this->actor);
        $report = ['title' => 'Учебные заявки', 'format' => 'csv', 'sections' => [$this->section('Заявки', [])]];
        $provider = $this->createMock(SiteRequestReportResponsesProvider::class);
        $provider->method('getModel')->willReturn('gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $calls = 0;
        $sawClarification = false;
        $provider->expects(self::atLeast(2))->method('responses')->willReturnCallback(static function (array $input) use (&$calls, &$sawClarification, $report): array {
            foreach ($input as $item) {
                if (($item['type'] ?? null) === 'function_call_output' && ($item['call_id'] ?? null) === 'current-project-report') {
                    $result = json_decode($item['output'], true, 512, JSON_THROW_ON_ERROR);
                    self::assertTrue($result['needs_clarification']);
                    self::assertSame('current_project_required', $result['reason']);
                    $sawClarification = true;
                }
            }
            $call = ['type' => 'function_call', 'id' => 'current-project-report', 'status' => 'completed', 'call_id' => 'current-project-report',
                'name' => 'generate_custom_report', 'arguments' => json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
            $first = ++$calls === 1;

            return ['content' => $first ? '' : 'Отчёт сформирован.', 'output' => $first ? [$call] : [], 'function_calls' => $first ? [$call] : [],
                'input_tokens' => 100, 'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
        });
        $files = $this->createMock(FileService::class);
        $files->expects(self::never())->method('putPrivate');
        $this->app->instance(FileService::class, $files);
        $org = $this->fixture->organization;
        app(AICreditService::class)->grant($org, 100000, 'purchase', null, 'current-project-report-'.Str::uuid());
        $query = 'Собери CSV по заявкам текущего проекта с полями название и статус.';
        $payload = ['request_id' => (string) Str::uuid(), 'message' => $query, 'profile' => 'normal', 'allow_actions' => false];
        $payload['quote_id'] = app(AICreditService::class)->quote($org, $this->actor, $payload)['quote_id'];
        $service = $this->app->makeWith(AIAssistantService::class, ['llmProvider' => $provider, 'ragRetriever' => null]);
        $response = $service->ask($query, (int) $org->id, $this->actor, null, $payload);
        self::assertTrue($sawClarification);
        self::assertTrue($response['message']['metadata']['needs_clarification']);
        self::assertSame(trans_message('ai_assistant_facts.live_proof_required'), $response['message']['content']);
        self::assertSame('partial', $response['message']['metadata']['validation_status']);
        self::assertSame([], $response['message']['metadata']['artifacts']);
        self::assertSame([], $response['message']['metadata']['source_refs']);
    }

    public function test_last_provider_call_preserves_the_generator_current_project_clarification(): void
    {
        config(['ai-assistant-credits.profiles.normal.max_calls' => 4]);
        $this->actor = $this->fixture->owner;
        $this->actingAs($this->actor);
        $report = ['title' => 'Учебные заявки', 'format' => 'csv', 'sections' => [$this->section('Заявки', [])]];
        $provider = $this->createMock(SiteRequestReportResponsesProvider::class);
        $provider->method('getModel')->willReturn('gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $calls = 0;
        $provider->expects(self::exactly(4))->method('responses')->willReturnCallback(static function () use (&$calls, $report): array {
            $last = ++$calls === 4;
            $id = 'last-project-report-'.$calls;
            $call = ['type' => 'function_call', 'id' => $id, 'status' => 'completed', 'call_id' => $id,
                'name' => $last ? 'generate_custom_report' : 'describe_custom_report_source',
                'arguments' => json_encode($last ? $report : ['domain' => 'site_requests', 'entity_type' => 'site_request', 'project_id' => null], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];

            return ['content' => '', 'output' => [$call], 'function_calls' => [$call], 'input_tokens' => 100,
                'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
        });
        $clarifications = 0;
        $logging = $this->createMock(LoggingService::class);
        $logging->method('technical')->willReturnCallback(static function (string $event, array $context) use (&$clarifications): void {
            if ($event === 'ai.assistant.current_project_scope_unavailable') {
                self::assertSame('current_project_required', $context['reason']);
                $clarifications++;
            }
        });
        $this->app->instance(LoggingService::class, $logging);
        $files = $this->createMock(FileService::class);
        $files->expects(self::never())->method('putPrivate');
        $this->app->instance(FileService::class, $files);
        $org = $this->fixture->organization;
        app(AICreditService::class)->grant($org, 100000, 'purchase', null, 'last-project-report-'.Str::uuid());
        $query = 'Собери CSV по заявкам текущего проекта с полями название и статус.';
        $payload = ['request_id' => (string) Str::uuid(), 'message' => $query, 'profile' => 'normal', 'allow_actions' => false];
        $payload['quote_id'] = app(AICreditService::class)->quote($org, $this->actor, $payload)['quote_id'];
        $service = $this->app->makeWith(AIAssistantService::class, ['llmProvider' => $provider, 'ragRetriever' => null]);
        $response = $service->ask($query, (int) $org->id, $this->actor, null, $payload);
        self::assertSame(4, $clarifications);
        self::assertTrue($response['message']['metadata']['needs_clarification']);
        self::assertSame(trans_message('ai_assistant_facts.live_proof_required'), $response['message']['content']);
        self::assertSame('partial', $response['message']['metadata']['validation_status']);
        self::assertSame([], $response['message']['metadata']['artifacts']);
        self::assertSame([], $response['message']['metadata']['source_refs']);
    }

    public static function reportFailures(): array
    {
        return ['row limit' => ['row_limit'], 'unavailable source' => ['unavailable'], 'internal failure' => ['internal']];
    }

    #[DataProvider('reportFailures')]
    public function test_file_request_preserves_safe_generator_failure_instead_of_model_success(string $failure): void
    {
        $request = $this->request($this->firstProject);
        if ($failure === 'row_limit') {
            $attributes = $request->getAttributes();
            unset($attributes['id']);
            SiteRequest::query()->insert(array_fill(0, 1000, $attributes));
        }
        $report = ['title' => 'Учебный отчёт', 'format' => 'xlsx', 'sections' => [$this->section('Материалы', [])]];
        $provider = $this->createMock(SiteRequestReportResponsesProvider::class);
        $provider->method('getModel')->willReturn('gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $calls = 0;
        $provider->expects(self::atLeast(2))->method('responses')->willReturnCallback(static function () use (&$calls, $report): array {
            $call = ['type' => 'function_call', 'id' => 'failed-report', 'status' => 'completed', 'call_id' => 'failed-report',
                'name' => 'generate_custom_report', 'arguments' => json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
            $first = ++$calls === 1;

            return ['content' => $first ? '' : 'Отчёт сформирован.', 'output' => $first ? [$call] : [], 'function_calls' => $first ? [$call] : [],
                'input_tokens' => 100, 'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
        });
        $files = $this->createMock(FileService::class);
        if ($failure === 'row_limit') {
            $files->expects(self::never())->method('putPrivate');
        } else {
            $files->expects(self::once())->method('putPrivate')->willThrowException($failure === 'unavailable'
                ? new ReportContractException(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE)
                : new \RuntimeException('PRIVATE_STORAGE_EXCEPTION_DO_NOT_SHOW'));
        }
        $this->app->instance(FileService::class, $files);
        $org = $this->fixture->organization;
        app(AICreditService::class)->grant($org, 100000, 'purchase', null, 'failed-report-'.Str::uuid());
        $query = 'Собери Excel по всем заявкам на материалы организации с названием, статусом и проектом.';
        $payload = ['request_id' => (string) Str::uuid(), 'message' => $query, 'profile' => 'normal', 'allow_actions' => false];
        $payload['quote_id'] = app(AICreditService::class)->quote($org, $this->actor, $payload)['quote_id'];
        $service = $this->app->makeWith(AIAssistantService::class, ['llmProvider' => $provider, 'ragRetriever' => null]);
        $response = $service->ask($query, (int) $org->id, $this->actor, null, $payload);
        $expected = $failure === 'unavailable' ? trans_message('reports.errors.report_source_unavailable') : trans_message('ai_custom_reports.failed');
        if ($failure === 'row_limit') {
            $expected .= "\n".trans_message('ai_custom_reports.too_many_rows');
        }
        self::assertSame($expected, $response['message']['content']);
        self::assertStringNotContainsString('PRIVATE_STORAGE_EXCEPTION_DO_NOT_SHOW', json_encode($response, JSON_THROW_ON_ERROR));
        self::assertSame([], $response['message']['metadata']['artifacts']);
        self::assertSame('unverified', $response['message']['metadata']['validation_status']);
        self::assertTrue($response['message']['metadata']['degraded_mode']);
        self::assertFalse($response['message']['metadata']['needs_clarification']);
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
