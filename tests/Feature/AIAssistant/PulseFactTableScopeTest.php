<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\Concerns\BuildsProjectPulseFacts;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class PulseFactTableScopeTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'pulse_fact_scope_fixture';

    public function test_one_schema_read_preserves_organization_project_and_deleted_filters(): void
    {
        [$actor, $organization] = $this->actor();
        $this->createTable();
        DB::table(self::TABLE)->insert([
            ['id' => 1, 'organization_id' => $organization->id, 'project_id' => 11, 'deleted_at' => null, 'policy_visible' => true],
            ['id' => 2, 'organization_id' => $organization->id + 1, 'project_id' => 11, 'deleted_at' => null, 'policy_visible' => true],
            ['id' => 3, 'organization_id' => $organization->id, 'project_id' => 22, 'deleted_at' => null, 'policy_visible' => true],
            ['id' => 4, 'organization_id' => $organization->id, 'project_id' => 11, 'deleted_at' => now(), 'policy_visible' => true],
            ['id' => 5, 'organization_id' => $organization->id, 'project_id' => 11, 'deleted_at' => null, 'policy_visible' => false],
        ]);
        $this->policy(2);
        $source = $this->source();

        foreach ([11 => [1], 0 => [1, 3]] as $projectId => $expected) {
            [$query, $schemaReads] = $this->measuredQuery($source, $this->context($actor, $organization, $projectId ?: null));
            self::assertSame($expected, $query->orderBy('id')->pluck('id')->all());
            self::assertSame(1, $schemaReads);
        }
    }

    public function test_optional_columns_are_not_assumed_to_exist(): void
    {
        [$actor, $organization] = $this->actor();
        Schema::create(self::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->boolean('policy_visible');
        });
        DB::table(self::TABLE)->insert([['id' => 1, 'policy_visible' => true], ['id' => 2, 'policy_visible' => false]]);
        $this->policy(1);

        [$query, $schemaReads] = $this->measuredQuery($this->source(), $this->context($actor, $organization, 11));

        self::assertSame([1], $query->pluck('id')->all());
        self::assertSame(1, $schemaReads);
    }

    public function test_each_call_reloads_schema_and_reapplies_current_policy(): void
    {
        [$actor, $organization] = $this->actor();
        Schema::create(self::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->boolean('policy_visible');
        });
        DB::table(self::TABLE)->insert([['id' => 1, 'organization_id' => $organization->id, 'policy_visible' => true], ['id' => 2, 'organization_id' => $organization->id, 'policy_visible' => false]]);
        $visible = true;
        $policy = Mockery::mock($this->app->make(AssistantDataAccessPolicy::class));
        $policy->shouldReceive('scopeTable')->twice()->andReturnUsing(static function (Builder $query) use (&$visible): Builder {
            return $query->where(self::TABLE.'.policy_visible', $visible);
        });
        $this->app->instance(AssistantDataAccessPolicy::class, $policy);
        $source = $this->source();
        $context = $this->context($actor, $organization, 11);
        [$first, $firstReads] = $this->measuredQuery($source, $context);
        self::assertSame([1], $first->pluck('id')->all());
        self::assertSame(1, $firstReads);

        Schema::table(self::TABLE, static fn (Blueprint $table) => $table->unsignedBigInteger('project_id')->default(11));
        DB::table(self::TABLE)->insert(['id' => 3, 'organization_id' => $organization->id, 'project_id' => 22, 'policy_visible' => false]);
        $visible = false;
        [$second, $secondReads] = $this->measuredQuery($source, $context);

        self::assertSame([2], $second->pluck('id')->all());
        self::assertSame(1, $secondReads);
    }

    public function test_missing_actor_does_not_read_schema_or_invoke_policy(): void
    {
        [$actor, $organization] = $this->actor();
        $this->createTable();
        DB::table(self::TABLE)->insert(['id' => 1, 'organization_id' => $organization->id, 'project_id' => 11, 'policy_visible' => true]);
        $policy = Mockery::mock($this->app->make(AssistantDataAccessPolicy::class));
        $policy->shouldNotReceive('scopeTable');
        $this->app->instance(AssistantDataAccessPolicy::class, $policy);

        foreach ([null, $actor->id + 1000000] as $userId) {
            $context = ProjectPulseContext::fromValidated(['project_id' => 11, 'use_ai' => false], $organization->id, $userId);
            [$query, $reads] = $this->measuredQuery($this->source(), $context);
            self::assertSame([], $query->pluck('id')->all());
            self::assertSame(0, $reads);
        }
    }

    public function test_failed_prefetch_retains_individual_column_checks(): void
    {
        [$actor, $organization] = $this->actor();
        $this->createTable();
        $this->policy(1);
        Schema::shouldReceive('getColumnListing')->once()->with(self::TABLE)->andThrow(new RuntimeException('metadata temporarily unavailable'));
        foreach (['organization_id', 'project_id', 'deleted_at'] as $column) {
            Schema::shouldReceive('hasColumn')->once()->with(self::TABLE, $column)->andReturn(true);
        }

        $query = $this->source()->queryFor($this->context($actor, $organization, 11), self::TABLE);

        self::assertSame([true, $organization->id, 11], $query->getBindings());
        self::assertStringContainsString('"'.self::TABLE.'"."deleted_at" is null', $query->toSql());
    }

    private function actor(): array
    {
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $actor = User::withoutEvents(fn () => User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]));

        return [$actor, $organization];
    }

    private function createTable(): void
    {
        Schema::create(self::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->timestamp('deleted_at')->nullable();
            $table->boolean('policy_visible');
        });
    }

    private function policy(int $calls): void
    {
        $policy = Mockery::mock($this->app->make(AssistantDataAccessPolicy::class));
        $policy->shouldReceive('scopeTable')->times($calls)->withArgs(static fn (Builder $query, User $actor, int $organizationId, string $table, bool $fresh): bool => $table === self::TABLE && $fresh)
            ->andReturnUsing(static fn (Builder $query): Builder => $query->where(self::TABLE.'.policy_visible', true));
        $this->app->instance(AssistantDataAccessPolicy::class, $policy);
    }

    private function context(User $actor, Organization $organization, ?int $projectId): ProjectPulseContext
    {
        return ProjectPulseContext::fromValidated(['project_id' => $projectId, 'use_ai' => false], $organization->id, $actor->id);
    }

    private function source(): PulseFactTableSource
    {
        return new PulseFactTableSource;
    }

    private function measuredQuery(PulseFactTableSource $source, ProjectPulseContext $context): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $query = $source->queryFor($context, self::TABLE);
            $schemaReads = collect(DB::getQueryLog())->filter(static fn (array $entry): bool => str_contains($entry['query'], 'pg_attribute') && str_contains($entry['query'], self::TABLE))->count();

            return [$query, $schemaReads];
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}

final class PulseFactTableSource
{
    use BuildsProjectPulseFacts { table as public queryFor; }
}
