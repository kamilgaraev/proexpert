<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Modules\PackageCatalogService;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantOrganizationActReportScopeTest extends TestCase
{
    use RefreshDatabase;

    private const QUESTION = 'Нужна сводка по всем актам организации в CSV: по каждому статусу и каждой валюте — количество актов и общая сумма. Валюты не смешивай, черновики тоже посчитай. Не ограничивайся выбранным проектом.';

    public static function reportCases(): array
    {
        return [
            'section project' => [true, false, false, 7, 5],
            'nested project filter' => [false, true, false, 7, 5],
            'both with date and status filters' => [true, true, true, 5, 3],
        ];
    }

    #[DataProvider('reportCases')]
    public function test_real_dispatch_keeps_organization_scope_and_current_permissions(bool $sectionProject, bool $nestedProject, bool $filtered, int $sourceRows, int $outputRows): void
    {
        Queue::fake();
        config(['cache.default' => 'array']);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(
            array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $actor = Model::withoutEvents(fn () => $fixture->addMember([
            'ai-assistant' => ['ai_assistant.chat', 'ai_assistant.reports.generate'],
            'project-management' => ['projects.view'],
            'contract-management' => ['contracts.view', 'contracts.performance_acts.view'],
        ], ['reports.view', 'finance.view']));
        $org = $fixture->organization;
        $projects = Model::withoutEvents(fn () => Project::factory()->count(3)->create(['organization_id' => $org->id, 'is_archived' => false]));
        [$first, $second, $private] = $projects->all();
        foreach ([$first, $second] as $project) {
            $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        }
        $foreign = Model::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id, 'is_archived' => false]));
        foreach ([[$first, 'approved', 'RUB', '10.00', '2026-10-10'], [$first, 'approved', 'USD', '1.00', '2026-10-10'],
            [$first, 'draft', 'USD', '3.00', '2026-10-10'], [$second, 'approved', 'RUB', '20.00', '2026-10-10'],
            [$second, 'draft', 'USD', '7.00', '2026-10-10'], [$second, 'annulled', 'RUB', '5.00', '2026-09-10'],
            [$second, 'rejected', 'EUR', '9.00', '2026-10-10'], [$private, 'approved', 'EUR', '9000.00', '2026-10-10'],
            [$foreign, 'approved', 'EUR', '8000.00', '2026-10-10']] as [$project, $status, $currency, $amount, $date]) {
            $this->act($project, $status, $currency, $amount, $date);
        }
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertTrue($policy->canReadDomain($actor, $org->id, 'contracts'));
        self::assertTrue($policy->canReadEntity($actor, $org->id, 'project', $second->id));
        self::assertFalse($policy->canReadEntity($actor, $org->id, 'project', $private->id));
        self::assertFalse($policy->canReadEntity($actor, $org->id, 'project', $foreign->id));
        $csv = null;
        $files = $this->createMock(FileService::class);
        $files->expects($this->once())->method('putPrivate')->willReturnCallback(function (string $path, $stream, string $mime, string $hash) use (&$csv): CurrentStoredFile {
            $csv = stream_get_contents($stream);
            self::assertSame(hash('sha256', $csv), $hash);

            return new CurrentStoredFile($path, 'etag', strlen($csv), $hash, $mime);
        });
        $this->app->instance(FileService::class, $files);
        $filters = $nestedProject ? [['field' => 'project_id', 'operator' => 'eq', 'value' => $first->id]] : [];
        if ($filtered) {
            $filters = [...$filters, ['field' => 'date', 'operator' => 'gte', 'value' => '2026-10-01'],
                ['field' => 'date', 'operator' => 'lte', 'value' => '2026-10-31'], ['field' => 'status', 'operator' => 'neq', 'value' => 'rejected']];
        }
        $arguments = ['title' => 'Акты организации', 'format' => 'csv', 'sections' => [[
            'title' => 'По статусам и валютам', 'domain' => 'acts', 'entity_type' => 'performance_act',
            'project_id' => $sectionProject ? $first->id : null, 'columns' => ['status', 'currency'], 'filters' => $filters,
            'group_by' => ['status', 'currency'], 'metrics' => [['field' => 'id', 'operation' => 'count', 'label' => 'Количество актов'],
                ['field' => 'total_amount', 'operation' => 'sum', 'label' => 'Общая сумма']],
            'order_by' => [['field' => 'status', 'direction' => 'asc'], ['field' => 'currency', 'direction' => 'asc']], 'row_limit' => 100,
        ]]];
        $question = self::QUESTION.($filtered ? ' Только за октябрь 2026 года, без отклонённых актов.' : '');
        $result = $this->dispatch($arguments, $question, $first, $org, $actor);
        self::assertSame('success', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame([['title' => 'По статусам и валютам', 'source_row_count' => $sourceRows, 'output_rows' => $outputRows]], $result['sections']);
        self::assertIsString($csv);
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringNotContainsString('9000', $csv);
        self::assertStringNotContainsString('8000', $csv);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);
        self::assertSame(['Статус', 'Валюта', 'Количество актов', 'Общая сумма'], array_shift($rows));
        $groups = array_map(static fn (array $row): array => [$row[1], (int) $row[2], number_format((float) $row[3], 2, '.', '')], $rows);
        $expected = [['RUB', 2, '30.00'], ['USD', 1, '1.00'], ['USD', 2, '10.00']];
        if (! $filtered) {
            $expected = [...$expected, ['RUB', 1, '5.00'], ['EUR', 1, '9.00']];
        }
        self::assertEqualsCanonicalizing($expected, $groups);
        $assignment = UserRoleAssignment::query()->where('user_id', $actor->id)->firstOrFail();
        OrganizationCustomRole::query()->findOrFail($assignment->custom_role_id)->updateQuietly(['system_permissions' => ['reports.view']]);
        $denied = $this->dispatch($arguments, $question, $first, $org, $actor);
        self::assertNotSame('success', $denied['status'] ?? null);
    }

    private function act(Project $project, string $status, string $currency, string $amount, string $date): void
    {
        Model::withoutEvents(function () use ($project, $status, $currency, $amount, $date): void {
            $contractor = Contractor::query()->create(['organization_id' => $project->organization_id, 'name' => 'Подрядчик акта']);
            $contract = Contract::query()->create(['organization_id' => $project->organization_id, 'project_id' => $project->id,
                'contractor_id' => $contractor->id, 'number' => 'C-'.bin2hex(random_bytes(5)), 'date' => '2026-09-01', 'total_amount' => '10000.00', 'currency' => $currency]);
            ContractPerformanceAct::query()->create(['contract_id' => $contract->id, 'project_id' => $project->id,
                'act_document_number' => 'ACT-'.bin2hex(random_bytes(5)), 'act_date' => $date, 'period_start' => $date, 'period_end' => $date,
                'amount' => $amount, 'currency' => $currency, 'status' => $status, 'is_approved' => $status === 'approved']);
        });
    }

    private function dispatch(array $arguments, string $question, Project $project, Organization $organization, User $actor): array
    {
        $service = app(AIAssistantService::class);
        $plan = ['request' => ['message' => $question, 'context' => ['entity_refs' => [['type' => 'project', 'id' => $project->id]]]],
            'request_understanding' => (new AssistantRequestUnderstandingResolver)->resolve($question)->toArray()];
        $call = ['name' => 'generate_custom_report', 'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)];
        $executed = null;
        $evidence = [];
        $failures = [];
        $proposed = [];
        $urls = [];
        $method = new \ReflectionMethod($service, 'handleToolCall');
        $result = $method->invokeArgs($service, [$call, $organization, $actor, (int) $organization->id, $plan, false,
            &$executed, &$evidence, &$failures, &$proposed, &$urls]);
        self::assertNull($executed);
        self::assertSame([], $proposed);
        self::assertIsArray($result);

        return $result;
    }
}
