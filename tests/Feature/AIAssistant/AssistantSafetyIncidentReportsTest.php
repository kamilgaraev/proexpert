<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportReference;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportSource;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyIncident;
use App\Models\Project;
use App\Services\Credits\AICreditService;
use App\Services\Modules\PackageCatalogService;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantSafetyIncidentReportsTest extends TestCase
{
    private const QUERY = 'Подготовь CSV по всем инцидентам охраны труда организации, включая закрытые и отменённые: номер, название, проект, тип, серьёзность, статус и дата происшествия. Не ограничивайся выбранным проектом.';

    private AssistantRealAuthorizationFixture $fixture;

    private Project $uiProject;

    private Project $otherProject;

    private array $incidentIds;

    private array $incidentNumbers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['cache.default' => 'array', 'app.timezone' => 'UTC']);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $organization = $this->fixture->organization;
        $this->uiProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organization->id, 'name' => 'Первый объект', 'is_archived' => false]));
        $this->otherProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organization->id, 'name' => 'Второй объект', 'is_archived' => false]));
        $foreign = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->foreignOrganization->id, 'is_archived' => false]));
        $this->incidentIds = [];
        $this->incidentNumbers = [];
        foreach ([[$this->uiProject, 'CLOSED', 'closed', '2026-10-18 21:30:00'],
            [$this->otherProject, 'CANCELLED', 'cancelled', '2026-10-18 12:00:00'],
            [$foreign, 'SECRET', 'reported', '2026-10-18 13:00:00']] as [$project, $number, $status, $occurredAt]) {
            $incident = SafetyIncident::withoutEvents(static fn () => SafetyIncident::query()->create([
                'organization_id' => $project->organization_id, 'project_id' => $project->id,
                'incident_number' => $number.'-'.Str::uuid(), 'title' => 'Учебная запись '.$number,
                'incident_type' => 'unsafe_condition', 'severity' => 'minor', 'status' => $status,
                'occurred_at' => $occurredAt,
            ]));
            if ($number !== 'SECRET') {
                $this->incidentIds[] = $incident->id;
                $this->incidentNumbers[] = $incident->incident_number;
            }
        }
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

    public function test_safety_source_exposes_occurred_datetime_with_explicit_moscow_label(): void
    {
        $source = app(AssistantCustomReportSource::class)->resolve($this->fixture->owner, $this->fixture->organization->id, 'safety', 'safety_incident', null);
        self::assertArrayHasKey('occurred_at', $source['fields']);
        self::assertSame('date', $source['fields']['occurred_at']['type']);
        self::assertSame('Дата и время происшествия (МСК)', $source['fields']['occurred_at']['label']);
    }

    public function test_safety_date_filters_follow_moscow_midnight_without_dropping_time(): void
    {
        $plan = ['title' => 'Происшествия', 'format' => 'csv', 'sections' => [[
            'title' => 'Происшествия', 'scope' => 'organization', 'domain' => 'safety', 'entity_type' => 'safety_incident',
            'project_id' => null, 'columns' => ['incident_number', 'occurred_at'], 'filters' => [],
            'group_by' => [], 'metrics' => [], 'order_by' => [['field' => 'id', 'direction' => 'asc']], 'row_limit' => 1000,
        ]]];
        $builder = app(AssistantCustomReportBuilder::class);
        $report = $builder->build($plan, $this->fixture->owner, $this->fixture->organization->id);
        self::assertSame([
            [$this->incidentNumbers[0], '2026-10-19 00:30:00'],
            [$this->incidentNumbers[1], '2026-10-18 15:00:00'],
        ], $report['sections'][0]['rows']);
        $plan['sections'][0]['filters'] = [['field' => 'occurred_at', 'operator' => 'eq', 'value' => '2026-10-19']];
        $filtered = $builder->build($plan, $this->fixture->owner, $this->fixture->organization->id);
        self::assertSame([[$this->incidentNumbers[0], '2026-10-19 00:30:00']], $filtered['sections'][0]['rows']);
        self::assertSame(1, $filtered['sections'][0]['source_row_count']);
    }

    public function test_exact_safety_csv_includes_both_projects_and_closed_statuses_with_real_authorization(): void
    {
        config(['ai-assistant.enabled' => true, 'ai-assistant-credits.enforce' => true,
            'ai-assistant-credits.profiles.normal.max_calls' => 5]);
        $actor = $this->fixture->owner;
        $organization = $this->fixture->organization;
        app(AICreditService::class)->grant($organization, 100000, 'purchase', null, 'safety-report-'.Str::uuid());
        $bytes = null;
        $files = $this->createMock(FileService::class);
        $files->expects(self::once())->method('putPrivate')->willReturnCallback(static function (string $path, $stream, string $mime, string $hash) use (&$bytes): CurrentStoredFile {
            $bytes = stream_get_contents($stream);
            self::assertSame(hash('sha256', $bytes), $hash);

            return new CurrentStoredFile($path, 'test-etag', strlen($bytes), $hash, $mime);
        });
        $this->app->instance(FileService::class, $files);
        $report = ['title' => 'Все инциденты охраны труда', 'format' => 'csv', 'sections' => [[
            'title' => 'Инциденты', 'scope' => 'organization', 'domain' => 'safety', 'entity_type' => 'safety_incident',
            'project_id' => (int) $this->uiProject->id,
            'columns' => ['incident_number', 'title', 'project_id', 'incident_type', 'severity', 'status', 'occurred_at'],
            'filters' => [], 'group_by' => [], 'metrics' => [], 'order_by' => [['field' => 'id', 'direction' => 'asc']], 'row_limit' => 1000,
        ]]];
        $calls = [['describe_custom_report_source', ['domain' => 'safety', 'entity_type' => 'safety_incident', 'project_id' => null]],
            ['generate_custom_report', $report]];
        $provider = $this->createMock(SafetyIncidentReportResponsesProvider::class);
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
            $id = 'safety-report-'.count($calls);
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
        $primary = array_values(array_filter($metadata['source_refs'], static fn (array $ref): bool => ($ref['_report_scope']['entity_type'] ?? null) === 'safety_incident'));
        self::assertCount(1, $primary);
        self::assertNull($primary[0]['project_id']);
        self::assertSame('organization', $primary[0]['_report_scope']['plan']['scope']);
        self::assertSame([], $primary[0]['_report_scope']['plan']['filters']);
        self::assertSame($this->incidentIds, array_map('intval', $primary[0]['_report_ids']));
        foreach ($metadata['source_refs'] as $ref) {
            self::assertTrue(app(AssistantCustomReportReference::class)->canReadReference($actor, $organization->id, $ref));
        }
        $stream = fopen('php://temp', 'w+b');
        self::assertNotFalse($stream);
        try {
            fwrite($stream, $bytes);
            rewind($stream);
            self::assertSame("\xEF\xBB\xBF", fread($stream, 3));
            self::assertSame(['Номер происшествия', 'Название', 'Идентификатор проекта', 'Тип происшествия', 'Важность', 'Статус', 'Дата и время происшествия (МСК)'], fgetcsv($stream, null, ';', '"', ''));
            $rows = [];
            while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
                $rows[] = $row;
            }
            self::assertSame([
                [$this->incidentNumbers[0], 'Учебная запись CLOSED', '#'.$this->uiProject->id.' — Первый объект', 'unsafe_condition', 'minor', 'Закрыто', '2026-10-19 00:30:00'],
                [$this->incidentNumbers[1], 'Учебная запись CANCELLED', '#'.$this->otherProject->id.' — Второй объект', 'unsafe_condition', 'minor', 'Отменено', '2026-10-18 15:00:00'],
            ], $rows);
        } finally {
            fclose($stream);
        }
    }

    public function test_safety_report_source_requires_real_safety_view_permission(): void
    {
        $member = Model::withoutEvents(fn () => $this->fixture->addMember(['ai-assistant' => ['ai_assistant.chat']], ['projects.view', 'reports.view']));
        $this->actingAs($member);
        $this->expectException(AccessDeniedHttpException::class);
        app(AssistantCustomReportSource::class)->resolve($member, $this->fixture->organization->id, 'safety', 'safety_incident', null);
    }
}

interface SafetyIncidentReportResponsesProvider extends LLMProviderInterface
{
    public function responses(array $input, array $options = []): array;
}
