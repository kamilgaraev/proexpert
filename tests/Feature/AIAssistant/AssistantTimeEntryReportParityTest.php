<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportRenderer;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\Modules\PackageCatalogService;
use App\Services\TimeTrackingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantTimeEntryReportParityTest extends TestCase
{
    protected function tearDown(): void
    {
        if (DB::transactionLevel() === 0) {
            DB::beginTransaction();
        }
        parent::tearDown();
    }

    public function test_csv_hours_filters_and_totals_match_native_visible_entries(): void
    {
        Queue::fake();
        config(['cache.default' => 'array']);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(
            array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $actor = Model::withoutEvents(fn () => $fixture->addMember([
            'ai-assistant' => ['ai_assistant.chat', 'ai_assistant.reports.generate'],
            'project-management' => ['projects.view'], 'time-tracking' => ['time_tracking.view'],
        ], ['reports.view']));
        $projects = Model::withoutEvents(fn () => Project::factory()->count(3)->create([
            'organization_id' => $fixture->organization->id, 'is_archived' => false,
        ]));
        [$visible, $private, $archived] = $projects->all();
        $archived->updateQuietly(['is_archived' => true]);
        foreach ([$visible, $archived] as $project) {
            $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        }
        $foreign = Model::withoutEvents(fn () => Project::factory()->create([
            'organization_id' => $fixture->foreignOrganization->id, 'is_archived' => false,
        ]));
        $create = static function (Project $project, ?string $stored, ?string $minutes, ?int $microseconds) use ($actor): TimeEntry {
            $entry = new TimeEntry;
            $entry->forceFill(['organization_id' => $project->organization_id, 'project_id' => $project->id,
                'user_id' => $actor->id, 'work_date' => '2026-10-10', 'title' => 'Учёт времени',
                'status' => 'draft', 'is_billable' => true, 'hours_worked' => $stored,
                'net_minutes' => $minutes, 'net_microseconds' => $microseconds])->saveQuietly();

            return $entry;
        };
        $entries = [$create($visible, '4.25', '180', 5400000000), $create($visible, '4.25', '150', null),
            $create($visible, '4.25', null, null), $create($visible, null, null, null), $create($visible, '4.25', '180', 0)];
        $create($private, '90.00', null, null);
        $create($archived, '80.00', null, null);
        $create($foreign, '70.00', null, null);
        $create($visible, '60.00', null, null)->deleteQuietly();
        $this->actingAs($actor);
        self::assertSame(1, DB::transactionLevel());
        DB::commit();

        $native = app(TimeTrackingService::class)->getTimeEntries($fixture->organization->id)->getCollection()->sortBy('id')->values();
        $expectedIds = array_map(static fn (TimeEntry $entry): int => (int) $entry->id, $entries);
        self::assertSame($expectedIds, $native->modelKeys());
        $expectedHours = $native->map(static fn (TimeEntry $entry): ?float => $entry->hours_worked)->all();
        self::assertSame([1.5, 2.5, 4.25, null, 0.0], $expectedHours);
        $section = ['title' => 'Учёт времени', 'domain' => 'time_tracking', 'entity_type' => 'time_entry',
            'project_id' => null, 'columns' => ['id', 'hours_worked'], 'filters' => [], 'group_by' => [],
            'metrics' => [], 'order_by' => [['field' => 'id', 'direction' => 'asc']]];
        $build = fn (array $source): array => app(AssistantCustomReportBuilder::class)->build(
            ['title' => 'Учёт времени', 'format' => 'csv', 'sections' => [$source]], $actor, $fixture->organization->id);
        $organizationReport = $build($section);
        $projectSection = array_replace($section, ['project_id' => $visible->id]);
        $projectReport = $build($projectSection);
        $stream = app(AssistantCustomReportRenderer::class)->render($projectReport);
        $csv = stream_get_contents($stream);
        fclose($stream);
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $csvRows = array_map(static fn (string $line): array => str_getcsv($line, ';', '"', ''),
            preg_split('/\r?\n/', trim(substr($csv, 3))));
        array_shift($csvRows);
        $actualHours = array_map(static fn (array $row): ?float => ($row[1] ?? '') === '' ? null : (float) $row[1], $csvRows);
        $filtered = $build(array_replace($projectSection, ['filters' => [['field' => 'hours_worked', 'operator' => 'gt', 'value' => 2]]]));
        $aggregate = $build(array_replace($projectSection, ['columns' => [], 'order_by' => [['field' => 'metric_1', 'direction' => 'desc']],
            'metrics' => [['field' => 'hours_worked', 'operation' => 'sum', 'label' => 'Часы']]]));
        $actual = ['visibleIds' => array_map(static fn (array $row): int => (int) $row[0], $organizationReport['sections'][0]['rows']),
            'csvIds' => array_map(static fn (array $row): int => (int) $row[0], $csvRows),
            'hours' => $actualHours, 'filteredIds' => array_map(static fn (array $row): int => (int) $row[0], $filtered['sections'][0]['rows']),
            'sum' => (float) $aggregate['sections'][0]['rows'][0][0]];
        self::assertSame(['visibleIds' => $expectedIds, 'csvIds' => $expectedIds, 'hours' => $expectedHours,
            'filteredIds' => [(int) $entries[1]->id, (int) $entries[2]->id], 'sum' => 8.25], $actual);
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertTrue($policy->canReadReference($actor, $fixture->organization->id, $projectReport['source_refs'][0]));
        $actor->assignedProjects()->updateExistingPivot($visible->id, ['is_active' => false]);
        self::assertFalse($policy->canReadReference($actor, $fixture->organization->id, $projectReport['source_refs'][0]));
        $this->expectException(AccessDeniedHttpException::class);
        $build($projectSection);
    }
}
