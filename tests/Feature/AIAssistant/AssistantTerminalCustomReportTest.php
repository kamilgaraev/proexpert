<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportAccessService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Enums\ProjectOrganizationRole;
use App\Models\ConstructionJournal;
use App\Models\Project;
use App\Services\Credits\AICreditService;
use App\Services\Modules\PackageCatalogService;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantTerminalCustomReportTest extends TestCase
{
    private const QUERY = 'Собери Excel для контроля журналов работ по всей организации: проект, название и номер журнала, статус, дата начала и окончания. Мне нужен полный реестр.';

    private AssistantRealAuthorizationFixture $fixture;

    private Project $uiProject;

    private array $journalIds;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['cache.default' => 'array', 'ai-assistant.enabled' => true, 'ai-assistant-credits.enforce' => true,
            'ai-assistant-credits.profiles.normal.max_calls' => 5]);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $org = $this->fixture->organization;
        $owner = $this->fixture->foreignOrganization;
        $this->uiProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org->id, 'is_archived' => false]));
        $ownProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org->id, 'is_archived' => false]));
        $sharedProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $owner->id, 'is_archived' => false]));
        $sharedProject->organizations()->attach($org->id, ['is_active' => true, 'role' => 'child_contractor', 'role_new' => ProjectOrganizationRole::SUBCONTRACTOR->value]);
        DB::table('project_organization_hierarchy')->insert([
            ['project_id' => $sharedProject->id, 'organization_id' => $owner->id, 'parent_organization_id' => null],
            ['project_id' => $sharedProject->id, 'organization_id' => $org->id, 'parent_organization_id' => $owner->id],
        ]);
        $this->journalIds = [];
        foreach ([$ownProject, $sharedProject] as $index => $project) {
            AuthorizationContext::getProjectContext((int) $project->id, (int) $org->id);
            $this->journalIds[] = Model::withoutEvents(fn () => ConstructionJournal::query()->create([
                'organization_id' => $project->organization_id, 'performing_organization_id' => $org->id, 'project_id' => $project->id,
                'name' => 'Журнал '.$index, 'journal_number' => 'TERMINAL-'.$index, 'start_date' => '2026-10-01',
                'status' => 'active', 'created_by_user_id' => $this->fixture->owner->id,
            ]))->id;
        }
        $this->actingAs($this->fixture->owner);
        app(AICreditService::class)->grant($org, 100000, 'purchase', null, 'terminal-report-'.Str::uuid());
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

    public function test_last_permitted_provider_call_publishes_real_orgwide_journal_workbook(): void
    {
        $bytes = null;
        $files = $this->createMock(FileService::class);
        $files->expects(self::once())->method('putPrivate')->willReturnCallback(static function (string $path, $stream, string $mime, string $hash) use (&$bytes): CurrentStoredFile {
            $bytes = stream_get_contents($stream);
            self::assertStringStartsWith('PK', $bytes);
            self::assertSame(hash('sha256', $bytes), $hash);

            return new CurrentStoredFile($path, 'test-etag', strlen($bytes), $hash, $mime);
        });
        $this->app->instance(FileService::class, $files);
        $provider = $this->scriptedProvider();
        $response = $this->ask($provider);
        $request = AssistantRequest::query()->where('request_id', $response['request_id'])->sole();
        self::assertSame('completed', $request->status);
        self::assertSame(5, $request->calls_used);
        self::assertSame(5, $request->max_calls);
        self::assertNotNull($bytes);
        self::assertSame('verified', $response['message']['metadata']['validation_status']);
        self::assertFalse($response['message']['metadata']['needs_clarification']);
        $artifacts = $response['message']['metadata']['artifacts'];
        self::assertCount(1, $artifacts);
        self::assertSame('excel', $artifacts[0]['type']);
        self::assertStringEndsWith('.xlsx', $artifacts[0]['filename']);
        $refs = $response['message']['metadata']['source_refs'];
        $journalRef = array_values(array_filter($refs, static fn (array $ref): bool => ($ref['_report_scope']['entity_type'] ?? null) === 'construction_journal'))[0];
        self::assertNull($journalRef['_report_scope']['project_id']);
        self::assertSame($this->journalIds, array_map('intval', $journalRef['_report_ids']));
        $access = app(AssistantReportAccessService::class);
        self::assertSame(1, preg_match('#/reports/([^/]+)/download$#', $artifacts[0]['download_url'], $download));
        self::assertNotEmpty($access->resolve($download[1], $this->fixture->owner));
        $path = tempnam(sys_get_temp_dir(), 'most-terminal-report-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, $bytes);
            $workbook = IOFactory::load($path);
            try {
                $sheet = $workbook->getActiveSheet();
                self::assertSame(7, $sheet->getHighestDataRow());
                self::assertSame('2', (string) $sheet->getCell('B3')->getValue());
                self::assertSame([['TERMINAL-0', 'Активный'], ['TERMINAL-1', 'Активный']], $sheet->rangeToArray('C6:D7'));
                self::assertSame([['2026-10-01'], ['2026-10-01']], $sheet->rangeToArray('E6:E7'));
            } finally {
                $workbook->disconnectWorksheets();
            }
        } finally {
            unlink($path);
        }
    }

    public function test_terminal_generator_requires_explicit_file_request(): void
    {
        $files = $this->createMock(FileService::class);
        $files->expects(self::never())->method('putPrivate');
        $this->app->instance(FileService::class, $files);
        $response = $this->ask($this->scriptedProvider(advertised: false), 'Покажи текущие журналы работ по всей организации: проект, название и номер журнала, статус, дата начала и окончания.');
        self::assertSame([], $response['message']['metadata']['artifacts']);
        self::assertSame(5, AssistantRequest::query()->where('request_id', $response['request_id'])->sole()->calls_used);
    }

    public function test_terminal_generator_cannot_change_requested_file_format(): void
    {
        $files = $this->createMock(FileService::class);
        $files->expects(self::never())->method('putPrivate');
        $this->app->instance(FileService::class, $files);
        $response = $this->ask($this->scriptedProvider(format: 'csv'));
        self::assertSame([], $response['message']['metadata']['artifacts']);
        self::assertSame(5, AssistantRequest::query()->where('request_id', $response['request_id'])->sole()->calls_used);
    }

    public function test_terminal_generator_rechecks_real_role_revocation(): void
    {
        $files = $this->createMock(FileService::class);
        $files->expects(self::never())->method('putPrivate');
        $this->app->instance(FileService::class, $files);
        $provider = $this->scriptedProvider(beforeFinal: fn () => $this->fixture->ownerAssignment->update(['is_active' => false]));
        try {
            $response = $this->ask($provider);
            self::assertSame([], $response['message']['metadata']['artifacts']);
        } catch (AuthorizationException|AccessDeniedHttpException) {
        }
        self::assertFalse($this->fixture->ownerAssignment->fresh()->is_active);
        self::assertSame(5, AssistantRequest::query()->where('organization_id', $this->fixture->organization->id)->sole()->calls_used);
        self::assertSame(0, DB::table('ai_assistant_report_access')->where('organization_id', $this->fixture->organization->id)->count());
    }

    public function test_multiple_terminal_generator_calls_do_not_write_files(): void
    {
        $files = $this->createMock(FileService::class);
        $files->expects(self::never())->method('putPrivate');
        $this->app->instance(FileService::class, $files);
        $response = $this->ask($this->scriptedProvider(multiple: true));
        self::assertSame([], $response['message']['metadata']['artifacts']);
        self::assertSame(5, AssistantRequest::query()->where('request_id', $response['request_id'])->sole()->calls_used);
    }

    private function ask(LLMProviderInterface $provider, string $query = self::QUERY): array
    {
        $actor = $this->fixture->owner;
        $org = $this->fixture->organization;
        $payload = ['request_id' => (string) Str::uuid(), 'message' => $query, 'profile' => 'normal', 'allow_actions' => false,
            'context' => ['entity_refs' => [['type' => 'project', 'id' => (int) $this->uiProject->id]]]];
        $quote = app(AICreditService::class)->quote($org, $actor, $payload);
        $payload['quote_id'] = $quote['quote_id'];
        $service = $this->app->makeWith(AIAssistantService::class, ['llmProvider' => $provider, 'ragRetriever' => null]);

        return $service->ask($query, (int) $org->id, $actor, null, $payload);
    }

    private function scriptedProvider(string $format = 'xlsx', bool $advertised = true, bool $multiple = false, ?callable $beforeFinal = null): TerminalReportResponsesProvider
    {
        $registry = app(AIToolRegistry::class);
        self::assertInstanceOf(\App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateCustomReportTool::class, $registry->getTool('generate_custom_report'));
        $discover = ['domain' => 'core_journals', 'entity_type' => 'construction_journal', 'offset' => 0, 'limit' => 3, 'field_offset' => 0, 'field_limit' => 16];
        $describe = ['domain' => 'core_journals', 'entity_type' => 'construction_journal', 'project_id' => null];
        $report = ['title' => 'Полный реестр журналов', 'format' => $format, 'sections' => [[
            'title' => 'Журналы', 'domain' => 'core_journals', 'entity_type' => 'construction_journal', 'project_id' => null,
            'columns' => ['project_id', 'name', 'journal_number', 'status', 'start_date', 'end_date'], 'row_limit' => 1000,
            'filters' => [], 'group_by' => [], 'metrics' => [], 'order_by' => [['field' => 'id', 'direction' => 'asc']],
        ]]];
        $calls = [['assistant_domain_discover_capabilities', $discover], ['assistant_domain_discover_capabilities', $discover],
            ['describe_custom_report_source', $describe], ['describe_custom_report_source', $describe], ['generate_custom_report', $report]];
        $provider = $this->createMock(TerminalReportResponsesProvider::class);
        $provider->method('getModel')->willReturn('gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $provider->expects(self::exactly(5))->method('responses')->willReturnCallback(static function (array $input, array $options) use (&$calls, $advertised, $multiple, $beforeFinal): array {
            $names = array_column($options['tools'] ?? [], 'name');
            self::assertSame($advertised, in_array('generate_custom_report', $names, true));
            if (count($calls) === 1 && $beforeFinal !== null) {
                $beforeFinal();
            }
            [$name, $arguments] = array_shift($calls);
            $id = 'terminal-report-'.(5 - count($calls));
            $call = ['type' => 'function_call', 'id' => $id, 'status' => 'completed', 'call_id' => $id, 'name' => $name,
                'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];

            $batch = [$call];
            if ($multiple && $name === 'generate_custom_report') {
                $batch[] = array_replace($call, ['id' => $id.'-second', 'call_id' => $id.'-second']);
            }

            return ['content' => '', 'output' => $batch, 'function_calls' => $batch, 'input_tokens' => 100,
                'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
        });

        return $provider;
    }
}

interface TerminalReportResponsesProvider extends LLMProviderInterface
{
    public function responses(array $input, array $options = []): array;
}
