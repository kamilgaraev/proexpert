<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantCoreBusinessMetadata as Metadata;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\CoreBusinessMoneyRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\CoreBusinessRagSource;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemTotal;
use App\Models\EstimateItemWork;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CoreBusinessRagEstimateChildrenTest extends TestCase
{
    public static function entities(): array
    {
        return [['core_estimate_item_work'], ['core_estimate_item_total']];
    }

    #[DataProvider('entities')]
    public function test_batch_preserves_chunks_across_pages_and_filters_parent_tenants_and_projects(string $entity): void
    {
        $organization = $this->organization();
        $foreign = $this->organization();
        $project = $this->project($organization);
        $shared = $this->project($foreign);
        Model::withoutEvents(fn () => $shared->organizations()->attach($organization->id, ['role' => 'contractor', 'is_active' => true]));
        $estimate = $this->estimate($organization, $project, 'OWN');
        $sharedEstimate = $this->estimate($organization, $shared, 'SHARED');
        $expected = [];
        for ($position = 1; $position <= 61; $position++) {
            $expected[] = $this->child($estimate, $position)[$entity]->id;
        }
        $sharedChild = $this->child($sharedEstimate, 1)[$entity];
        $expected[] = $sharedChild->id;
        $this->child($this->estimate($foreign, $shared, 'FOREIGN'), 1);
        $this->child($this->estimate($organization, $this->project($foreign), 'DENIED-PROJECT'), 1);
        $deletedEstimate = $this->estimate($organization, $project, 'DELETED-ESTIMATE');
        $this->child($deletedEstimate, 1);
        $deletedEstimate->deleteQuietly();
        $deletedItem = $this->child($estimate, 62)['item'];
        $deletedItem->deleteQuietly();
        $deletedProject = $this->project($organization);
        $this->child($this->estimate($organization, $deletedProject, 'DELETED-PROJECT'), 1);
        $deletedProject->deleteQuietly();

        $collector = new EstimateChildBatchTestSource($entity);
        $chunks = [...$collector->collectForOrganization($organization->id)];
        self::assertSame($expected, array_column($chunks, 'entityId'));
        self::assertCount(62, $chunks);
        foreach ($chunks as $chunk) {
            self::assertSame($organization->id, $chunk->organizationId);
            self::assertSame($entity, $chunk->entityType);
            self::assertSame($collector->sourceType(), $chunk->sourceType);
            self::assertContains($chunk->projectId, [$project->id, $shared->id]);
            self::assertSame($chunk->projectId, $chunk->metadata['project_id']);
        }
        self::assertSame(array_slice($expected, 0, 61), array_column([...$collector->collectForOrganization($organization->id, $project->id)], 'entityId'));
        self::assertSame([$sharedChild->id], array_column([...$collector->collectForOrganization($organization->id, $shared->id)], 'entityId'));
        foreach ([$chunks[0], $chunks[61]] as $chunk) {
            self::assertEquals([$chunk], [...$collector->collectEntity($organization->id, $entity, $chunk->entityId)]);
        }
        if ($entity === 'core_estimate_item_total') {
            self::assertEquals([$chunks[0]], [...(new CoreBusinessMoneyRagSource())->collectEntity($organization->id, $entity, $chunks[0]->entityId)]);
        }
    }

    #[DataProvider('entities')]
    public function test_reused_collector_observes_sharing_soft_deletion_and_parent_moves(string $entity): void
    {
        $organization = $this->organization();
        $foreign = $this->organization();
        $shared = $this->project($foreign);
        Model::withoutEvents(fn () => $shared->organizations()->attach($organization->id, ['role' => 'contractor', 'is_active' => true]));
        $estimate = $this->estimate($organization, $shared, 'OWN');
        $foreignEstimate = $this->estimate($foreign, $shared, 'FOREIGN');
        $child = $this->child($estimate, 1);
        $collector = new EstimateChildBatchTestSource($entity);
        $ids = fn (): array => array_column([...$collector->collectForOrganization($organization->id)], 'entityId');
        self::assertSame([$child[$entity]->id], $ids());
        Model::withoutEvents(fn () => $shared->organizations()->updateExistingPivot($organization->id, ['is_active' => false]));
        self::assertSame([], $ids());
        Model::withoutEvents(fn () => $shared->organizations()->updateExistingPivot($organization->id, ['is_active' => true]));
        self::assertSame([$child[$entity]->id], $ids());
        $estimate->updateQuietly(['organization_id' => $foreign->id]);
        self::assertSame([], $ids());
        $estimate->updateQuietly(['organization_id' => $organization->id]);
        self::assertSame([$child[$entity]->id], $ids());
        $shared->deleteQuietly();
        self::assertSame([], $ids());
        $shared->restoreQuietly();
        self::assertSame([$child[$entity]->id], $ids());
        $estimate->deleteQuietly();
        self::assertSame([], $ids());
        $estimate->restoreQuietly();
        self::assertSame([$child[$entity]->id], $ids());
        $child['item']->deleteQuietly();
        self::assertSame([], $ids());
        $child['item']->restoreQuietly();
        self::assertSame([$child[$entity]->id], $ids());
        $child['item']->updateQuietly(['estimate_id' => $foreignEstimate->id]);
        self::assertSame([], $ids());
        $child['item']->updateQuietly(['estimate_id' => $estimate->id]);
        self::assertSame([$child[$entity]->id], $ids());
    }

    #[DataProvider('entities')]
    public function test_empty_tenant_does_not_receive_foreign_children_with_many_siblings(string $entity): void
    {
        $organization = $this->organization();
        $foreign = $this->organization();
        $project = $this->project($organization);
        $this->estimate($organization, $project, 'EMPTY');
        $estimate = $this->estimate($foreign, $this->project($foreign), 'FOREIGN');
        for ($position = 1; $position <= 80; $position++) {
            $this->child($estimate, $position);
        }
        $collector = new EstimateChildBatchTestSource($entity);
        self::assertSame([], [...$collector->collectForOrganization($organization->id)]);
        self::assertSame([], [...$collector->collectForOrganization($organization->id, $project->id)]);
    }

    #[DataProvider('entities')]
    public function test_large_parent_sets_keep_two_pages_within_a_linear_join_work_budget(string $entity): void
    {
        $organization = $this->organization();
        $foreign = $this->organization();
        $foreignEstimate = $this->estimate($foreign, $this->project($foreign), 'FOREIGN');
        $estimate = $this->estimate($organization, $this->project($organization), 'OWN');
        $table = $entity === 'core_estimate_item_work' ? 'estimate_item_works' : 'estimate_item_totals';
        $foreignItems = $this->bulkChildren($foreignEstimate, $table, 20000, 1);
        $ownItems = $this->bulkChildren($estimate, $table, 2000, 2);
        DB::statement('ANALYZE estimates, estimate_items, estimate_item_works, estimate_item_totals, projects, project_organization');
        $expected = DB::table($table)->whereIn('estimate_item_id', $ownItems)->orderBy('id')->limit(51)->pluck('id')->all();
        $reads = [];
        DB::listen(static function (QueryExecuted $query) use (&$reads, $table): void {
            if (str_starts_with($query->sql, 'select "'.$table.'".')) {
                $reads[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
            }
        });
        $chunks = [];
        foreach ((new EstimateChildBatchTestSource($entity))->collectForOrganization($organization->id) as $chunk) {
            $chunks[] = $chunk;
            if (count($chunks) === 51) {
                break;
            }
        }
        self::assertSame($expected, array_column($chunks, 'entityId'));
        self::assertCount(2, $reads);
        $joinRejections = 0;
        $itemVisits = 0;
        $walk = static function (array $node) use (&$walk, &$joinRejections, &$itemVisits): void {
            $loops = $node['Actual Loops'] ?? 0;
            $joinRejections += ($node['Rows Removed by Join Filter'] ?? 0) * $loops;
            if (($node['Relation Name'] ?? null) === 'estimate_items') {
                $itemVisits += (($node['Actual Rows'] ?? 0) + ($node['Rows Removed by Filter'] ?? 0)) * $loops;
            }
            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child);
            }
        };
        foreach ($reads as $read) {
            $rows = DB::select('EXPLAIN (ANALYZE TRUE, TIMING FALSE, SUMMARY TRUE, FORMAT JSON) '.$read['sql'], $read['bindings']);
            $plan = json_decode($rows[0]->{'QUERY PLAN'}, true, 64, JSON_THROW_ON_ERROR);
            self::assertSame(50, $plan[0]['Plan']['Actual Rows']);
            $walk($plan[0]['Plan']);
        }
        $parentCount = count($foreignItems) + count($ownItems);
        $rootCount = count($foreignItems) + 2 * count($ownItems);
        self::assertLessThanOrEqual(4 * ($parentCount + $rootCount), $joinRejections);
        self::assertLessThanOrEqual(4 * $parentCount, $itemVisits);
    }

    private function organization(): Organization
    {
        return Model::withoutEvents(fn (): Organization => Organization::factory()->create());
    }

    private function project(Organization $organization): Project
    {
        return Model::withoutEvents(fn (): Project => Project::factory()->create(['organization_id' => $organization->id]));
    }

    private function estimate(Organization $organization, Project $project, string $number): Estimate
    {
        return Model::withoutEvents(fn (): Estimate => Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => $number,
            'name' => 'Estimate '.$number,
            'estimate_date' => '2026-10-21',
            'type' => 'local',
            'status' => 'draft',
        ]));
    }

    private function child(Estimate $estimate, int $position): array
    {
        return Model::withoutEvents(function () use ($estimate, $position): array {
            $item = EstimateItem::query()->create([
                'estimate_id' => $estimate->id,
                'position_number' => (string) $position,
                'name' => 'Item '.$position,
                'item_type' => 'work',
            ]);

            return [
                'item' => $item,
                'core_estimate_item_work' => EstimateItemWork::query()->create(['estimate_item_id' => $item->id, 'caption' => 'Work '.$position]),
                'core_estimate_item_total' => EstimateItemTotal::query()->create(['estimate_item_id' => $item->id, 'caption' => 'Total '.$position]),
            ];
        });
    }

    private function bulkChildren(Estimate $estimate, string $table, int $count, int $siblings): array
    {
        $rows = [];
        for ($position = 1; $position <= $count; $position++) {
            $rows[] = ['estimate_id' => $estimate->id, 'position_number' => (string) $position, 'name' => 'Item '.$position, 'item_type' => 'work'];
            if (count($rows) === 500) {
                DB::table('estimate_items')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('estimate_items')->insert($rows);
        }
        $ids = DB::table('estimate_items')->where('estimate_id', $estimate->id)->orderBy('id')->pluck('id')->all();
        $rows = [];
        foreach ($ids as $id) {
            for ($sibling = 1; $sibling <= $siblings; $sibling++) {
                $rows[] = ['estimate_item_id' => $id, 'caption' => 'Child '.$sibling];
                if (count($rows) === 500) {
                    DB::table($table)->insert($rows);
                    $rows = [];
                }
            }
        }
        if ($rows !== []) {
            DB::table($table)->insert($rows);
        }

        return $ids;
    }
}

final class EstimateChildBatchTestSource extends CoreBusinessRagSource
{
    public function __construct(private readonly string $entity)
    {
    }

    public function sourceType(): string
    {
        return $this->entity === 'core_estimate_item_total' ? 'core_business_money' : 'core_business';
    }

    public function entities(): array
    {
        return [$this->entity => Metadata::records()[$this->entity]];
    }
}
