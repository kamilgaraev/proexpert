<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\RagExpectedSource;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantAclQueryCompiler;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Models\Project;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssistantOfflineAclFixture;

final class AssistantAclQueryCompilerTest extends TestCase
{
    private mixed $previousApp;
    private mixed $previousResolver;
    private AssistantDataAccessPolicy $policy;
    private \App\Models\User $actor;
    private mixed $offlineConnection;

    protected function setUp(): void
    {
        $this->previousApp = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        Facade::clearResolvedInstances();
        [$this->policy, $this->actor, $this->offlineConnection] = AssistantOfflineAclFixture::create();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApp);
        Container::setInstance($this->previousApp);
        if ($this->previousResolver !== null) { Model::setConnectionResolver($this->previousResolver); }
        else { Model::unsetConnectionResolver(); }
    }

    public function test_purchase_order_parent_accepts_order_read_permission_without_opening_generic_procurement_domain(): void
    {
        $authorization = Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $allowedOrder = true;
        $allowedFinance = false;
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $authorization->shouldReceive('canCurrent')->andReturnUsing(static function (\App\Models\User $actor, string $permission) use (&$allowedOrder, &$allowedFinance): bool {
            return $permission === 'contracts.view' || ($permission === 'procurement.purchase_orders.view' && $allowedOrder)
                || ($permission === 'finance.view' && $allowedFinance);
        });
        $app = Facade::getFacadeApplication();
        $policy = new AssistantDataAccessPolicy($authorization, $app->make(\App\Services\Project\UserProjectAccessService::class), $app->make(\App\Services\Entitlements\OrganizationEntitlementService::class));
        self::assertFalse($policy->canReadDomain($this->actor, 1, 'procurement'));
        self::assertSame('procurement_business', AssistantDataAccessPolicy::entityDefinitions()['purchase_order'][2]);
        self::assertTrue($policy->canReadDomain($this->actor, 1, 'procurement_business'));
        self::assertNotContains('procurement', $policy->allowedSourceTypes($this->actor, 1));
        $order = $policy->entityQuery($this->actor, 1, 'purchase_order');
        self::assertNotNull($order);
        $sql = $order->toSql();
        self::assertStringContainsString('"purchase_orders"."contract_id"', $sql);
        self::assertStringContainsString('"contracts"."project_id"', $sql);
        self::assertStringContainsString('"purchase_requests"."organization_id"', $sql);
        self::assertStringContainsString('"site_requests"."project_id"', $sql);
        self::assertStringContainsString('"organization_id" = ?', $sql);
        self::assertFalse($policy->canReadDomain($this->actor, 1, 'procurement'));
        $allowedFinance = true;
        self::assertContains('procurement', $policy->allowedSourceTypes($this->actor, 1));
        $allowedOrder = false;
        self::assertNull($policy->entityQuery($this->actor, 1, 'purchase_order'));
        self::assertNotContains('procurement', $policy->allowedSourceTypes($this->actor, 1));
    }

    public function test_source_domains_are_checked_once_per_call_and_permission_and_module_revocation_are_fresh(): void
    {
        $permission = \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantSalesBusinessMetadata::domainGates()['crm_business'][1][0];
        $allowed = true;
        $moduleActive = true;
        $calls = 0;
        $authorization = Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $authorization->shouldReceive('canCurrent')->andReturnUsing(static function (\App\Models\User $actor, string $candidate) use ($permission, &$allowed, &$calls): bool {
            if ($candidate !== $permission) { return false; }
            $calls++;
            return $allowed;
        });
        $modules = Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturnUsing(static function () use (&$moduleActive) {
            return collect($moduleActive ? [(object) ['slug' => 'crm']] : []);
        });
        $policy = new AssistantDataAccessPolicy($authorization, Facade::getFacadeApplication()->make(\App\Services\Project\UserProjectAccessService::class), $modules);
        self::assertGreaterThan(1, count(array_filter(AssistantDataAccessPolicy::entityDefinitions(), static fn (array $definition): bool => $definition[2] === 'crm_business')));
        self::assertContains('crm_business', $policy->allowedSourceTypes($this->actor, 1));
        self::assertSame(1, $calls);
        $allowed = false;
        self::assertNotContains('crm_business', $policy->allowedSourceTypes($this->actor, 1));
        self::assertSame(2, $calls);
        $allowed = true;
        $moduleActive = false;
        self::assertNotContains('crm_business', $policy->allowedSourceTypes($this->actor, 1));
        self::assertSame(2, $calls);
    }

    public function test_expected_projection_keeps_identity_acl_without_content_columns_and_real_sources_keep_schema_guard(): void
    {
        $projection = $this->policy->applyToExpectedSources(RagExpectedSource::query(), $this->actor, 1)->limit(1)->toSql();
        self::assertStringNotContainsString('"ai_rag_expected_sources"."metadata"', $projection);
        self::assertStringContainsString('"ai_rag_expected_sources"."organization_id" = ?', $projection);
        self::assertStringContainsString('"ai_rag_expected_sources"."entity_type" = ?', $projection);
        self::assertStringContainsString('1 = 0', $projection);
        self::assertStringContainsString('organization_user', $projection);
        self::assertStringContainsString('"is_active" = ?', $projection);
        self::assertStringContainsString('from "projects"', $projection);
        $sources = $this->policy->applyToSources(RagSource::query(), $this->actor, 1)->toSql();
        self::assertStringContainsString('"ai_rag_sources"."metadata"', $sources);
        self::assertStringContainsString('assistant_public_schema_revision', $sources);
    }

    public function test_payment_estimate_split_projection_skips_missing_price_columns_and_keeps_parent_acl(): void
    {
        $type = 'core_payment_document_estimate_split';
        $declared = \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantCoreBusinessMetadata::safeSelectColumns()[$type];
        self::assertContains('quantity', $declared);

        $schema = Facade::getFacadeApplication()->make('db.schema');
        Schema::swap(new class($schema) {
            public function __construct(private readonly object $schema) {}

            public function getColumnListing(string $table): array
            {
                if ($table === 'payment_document_estimate_splits') {
                    return ['id', 'payment_document_id', 'estimate_item_id', 'amount', 'percentage', 'created_at', 'updated_at'];
                }

                return $this->schema->getColumnListing($table);
            }

            public function __call(string $method, array $arguments): mixed
            {
                return $this->schema->{$method}(...$arguments);
            }
        });

        $query = $this->policy->entityQuery($this->actor, 1, $type);
        self::assertNotNull($query);
        $sql = $query->toSql();
        foreach (['quantity', 'unit_price_plan', 'unit_price_actual', 'price_deviation'] as $column) {
            self::assertStringNotContainsString('"payment_document_estimate_splits"."'.$column.'"', $sql);
        }
        self::assertStringContainsString('"payment_document_estimate_splits"."amount"', $sql);
        self::assertStringContainsString('"payment_document_estimate_splits"."payment_document_id"', $sql);
        self::assertStringContainsString('"payment_document_estimate_splits"."estimate_item_id"', $sql);
        self::assertStringContainsString('payment_documents', $sql);
        self::assertStringContainsString('estimate_items', $sql);
    }

    public function test_expected_projection_scope_cannot_bypass_real_source_schema_guard(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy->applyToExpectedSources(RagSource::query(), $this->actor, 1);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('projectionTableSubstitutions')]
    public function test_expected_projection_scope_rejects_table_substitutions(string $substitution): void
    {
        $query = match ($substitution) {
            'from' => RagExpectedSource::query()->from('ai_rag_sources'),
            'setTable' => (new RagExpectedSource)->setTable('ai_rag_sources')->newQuery(),
            'alias' => RagExpectedSource::query()->from('ai_rag_sources as ai_rag_expected_sources'),
        };
        $this->expectException(\InvalidArgumentException::class);
        $this->policy->applyToExpectedSources($query, $this->actor, 1);
    }

    public static function projectionTableSubstitutions(): iterable
    {
        yield 'actual source from' => ['from'];
        yield 'actual source model table' => ['setTable'];
        yield 'actual source alias' => ['alias'];
    }
    public function test_full_source_acl_is_bounded_and_precedes_ranking_and_limit(): void
    {
        $query = $this->policy->applyToSources(RagSource::query(), $this->actor, 1)->orderByDesc('id')->limit(8);
        $sql = $query->toSql();
        $types = count(AssistantDataAccessPolicy::entityDefinitions());
        self::assertLessThan(600 * $types, strlen($sql));
        self::assertLessThan(3 * $types, substr_count(strtolower($sql), 'select '));
        preg_match_all('/"(assistant_acl_\d+)" AS MATERIALIZED/', $sql, $names);
        self::assertNotEmpty($names[1]);
        self::assertSame(count($names[1]), count(array_unique($names[1])));
        self::assertStringContainsString('source_type', $sql);
        self::assertStringContainsString('entity_type', $sql);
        self::assertStringContainsString('assistant_public_schema_revision', $sql);
        self::assertStringEndsWith('order by "id" desc limit 8', $sql);
        self::assertGreaterThan(strpos($sql, 'AS MATERIALIZED'), strrpos($sql, 'order by'));
    }

    public function test_every_reference_is_checked_and_contexts_do_not_share_scopes(): void
    {
        $query = $this->policy->entityQuery($this->actor, 1, 'project_pulse_report');
        self::assertNotNull($query);
        self::assertLessThan(400 * count(AssistantDataAccessPolicy::entityDefinitions()), strlen($query->toSql()));
        self::assertStringContainsString('NOT EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(project_pulse_reports.source_refs)', $query->toSql());
        self::assertStringContainsString('WHERE NOT COALESCE', $query->toSql());
        self::assertStringContainsString("ref - 'entity_type' - 'entity_id'", $query->toSql());
        self::assertStringContainsString("jsonb_typeof(ref->'entity_id') IN ('string', 'number')", $query->toSql());
        foreach (['project', 'contract', 'estimate', 'purchase_order', 'purchase_receipt_return'] as $type) {
            self::assertContains($type, $query->getBindings());
        }
        self::assertNull($this->policy->entityQuery($this->actor, 2, 'project'));
        $compiler = new AssistantAclQueryCompiler(1, 1);
        self::assertTrue($compiler->accepts(1, 1));
        self::assertFalse($compiler->accepts(2, 1));
        self::assertFalse($compiler->accepts(1, 2));
    }

    public function test_shared_parent_cte_is_immutable_and_matches_keep_their_bindings(): void
    {
        $compiler = new AssistantAclQueryCompiler(1, 1);
        $compiler->register('project', Project::query()->where('organization_id', 1), ['id']);
        $first = $compiler->reference('project');
        $second = $compiler->reference('project');
        self::assertNotNull($first);
        self::assertNotNull($second);
        $first->where('projects.id', 11);
        $second->where('projects.id', 22);
        $query = Project::query()->whereIn('id', $first->select('projects.id'))->orWhereIn('id', $second->select('projects.id'));
        $finished = $compiler->finish($query);
        self::assertSame(1, substr_count($finished->toSql(), 'AS MATERIALIZED'));
        self::assertSame([1, 11, 22, 1, true, 1, 1, 1, true], $finished->getBindings());
        self::assertSame([], $compiler->reference('project')?->getBindings());
    }

    public function test_cached_subtree_keeps_depth_guard_and_internal_projection_columns_stay_private(): void
    {
        $compiler = new AssistantAclQueryCompiler(1, 1);
        $compiler->register('leaf', Project::query()->select('projects.id')->where('organization_id', 1), ['name'], ['branch', 'leaf']);
        $leaf = $compiler->reference('leaf', ['branch']);
        self::assertNotNull($leaf);
        $compiler->register('branch', Project::query()->whereIn('id', $leaf->select('projects.id')), [], ['branch']);
        self::assertNull($compiler->reference('branch', array_fill(0, 15, 'ancestor')));
        self::assertNotNull($compiler->reference('branch', array_fill(0, 14, 'ancestor')));
        self::assertNull($compiler->reference('branch', ['branch']));
        $root = $compiler->reference('leaf');
        self::assertNotNull($root);
        $finished = $compiler->finish($root);
        self::assertSame(['projects.id'], $finished->getQuery()->columns);
        self::assertStringContainsString('select "projects"."id", "projects"."name"', $finished->toSql());
        self::assertStringContainsString('select "projects".* from "assistant_acl_0" as "projects"', $finished->toSql());
        self::assertStringStartsWith('select "projects"."name"', $finished->select('projects.name')->toSql());
    }

    public function test_outer_bound_projection_precedes_cte_and_filter_bindings(): void
    {
        $compiler = new AssistantAclQueryCompiler(1, 1);
        $compiler->register('project', Project::query()->where('organization_id', 1), ['id']);
        $query = Project::query()->selectRaw('? as probe', ['marker'])->where('projects.id', 11);
        $finished = $compiler->finish($query);
        self::assertSame(9, substr_count($finished->toSql(), '?'));
        self::assertSame(['marker', 1, 11, 1, true, 1, 1, 1, true], $finished->getBindings());
        self::assertSame(['marker'], $finished->getQuery()->getRawBindings()['select']);
        self::assertSame([1, 11], $finished->getQuery()->getRawBindings()['from']);
        self::assertSame(['marker', 11], $query->getBindings());
    }

    public function test_registered_bound_projection_keeps_each_expression_binding_on_its_own_level(): void
    {
        $compiler = new AssistantAclQueryCompiler(1, 1);
        $query = Project::query()->select(['projects.id', 'projects.name'])
            ->selectRaw('CASE WHEN projects.name = ? THEN ? ELSE ? END as probe', ['alpha', 'yes', 'no'])
            ->where('organization_id', 1);
        $reference = $compiler->register('project', $query, ['id']);
        self::assertSame(['alpha', 'yes', 'no'], $reference->getBindings());
        $finished = $compiler->finish($reference->where('projects.id', 11));
        self::assertSame(14, substr_count($finished->toSql(), '?'));
        self::assertSame(['alpha', 'yes', 'no', 'alpha', 'yes', 'no', 1, 11, 1, true, 1, 1, 1, true], $finished->getBindings());
        self::assertSame(['alpha', 'yes', 'no'], $compiler->reference('project')?->getBindings());
        self::assertSame(['alpha', 'yes', 'no', 1], $query->getBindings());
    }

    public function test_schema_absence_removes_only_the_invalid_branch_and_is_rechecked_next_compile(): void
    {
        $original = Schema::getFacadeRoot();
        $schema = new class($original) {
            public bool $missing = true;
            public function __construct(private mixed $original) {}
            public function getColumnListing(string $table): array { return $this->missing && $table === 'estimate_library_items' ? [] : $this->original->getColumnListing($table); }
            public function hasColumn(string $table, string $column): bool { return $this->original->hasColumn($table, $column); }
        };
        Schema::swap($schema);
        self::assertNull($this->policy->entityQuery($this->actor, 1, 'estimate_library_item'));
        $sql = $this->policy->applyToSources(RagSource::query(), $this->actor, 1)->toSql();
        self::assertStringNotContainsString('from "estimate_library_items"', $sql);
        self::assertStringContainsString('from "projects"', $sql);
        $schema->missing = false;
        self::assertNotNull($this->policy->entityQuery($this->actor, 1, 'estimate_library_item'));
    }

    public function test_membership_and_domain_reads_are_bounded_per_compile_with_fresh_sql_guards(): void
    {
        $before = $this->offlineConnection->fake;
        $sql = $this->policy->applyToSources(RagSource::query(), $this->actor, 1)->toSql();
        self::assertLessThan(30, $this->offlineConnection->fake - $before);
        self::assertStringContainsString('exists (select "users"."id" from "users"', $sql);
        self::assertStringContainsString('exists (select 1 from "organization_user"', $sql);
        self::assertStringContainsString('"current_organization_id" = ?', $sql);
        $calls = 0;
        $compiler = new AssistantAclQueryCompiler(1, 1);
        $resolve = static function () use (&$calls): bool { $calls++; return true; };
        self::assertTrue($compiler->remember('membership', $resolve));
        self::assertTrue($compiler->remember('membership', $resolve));
        self::assertSame(1, $calls);
        self::assertTrue((new AssistantAclQueryCompiler(1, 1))->remember('membership', $resolve));
        self::assertSame(2, $calls);
    }
}
