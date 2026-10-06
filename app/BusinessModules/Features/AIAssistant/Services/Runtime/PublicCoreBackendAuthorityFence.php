<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Runtime;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\RoleCondition;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\ModulePermissionChecker;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Domain\Authorization\Services\RoleScanner;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Logging\LoggingService;
use App\Services\Modules\PackageCatalogService;
use App\Services\Project\UserProjectAccessService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use LogicException;
use Throwable;

class PublicCoreBackendAuthorityFence
{
    private const ACQUIRE_MS = 250;
    private const GUARD_MS = 2000;

    public function __construct(private readonly ?Connection $connection = null,
        private readonly ?LoggingService $logging = null)
    {
    }

    public function available(): bool
    {
        return false;
    }

    public function withCurrent(array $ownedTicket, Closure $operation): mixed
    {
        throw new LogicException('authorization_changed');
    }

    public function liveSnapshot(): array
    {
        throw new LogicException('authorization_changed');
    }

    public function inspectSourceCandidate(User $actor, int $organizationId, Request $origin, Closure $inspect): mixed
    {
        if ($this->connection === null || $this->logging === null || $organizationId <= 0
            || $actor->id <= 0 || filter_var($origin->ip(), FILTER_VALIDATE_IP) === false) {
            throw new LogicException('authorization_changed');
        }
        $connection = $this->connection;
        $this->assertDatasource($connection);
        $generation = $this->policyGeneration();
        $policy = $this->freshPolicy($this->logging);
        $started = hrtime(true);
        $acquireDeadline = $started + self::ACQUIRE_MS * 1000000;
        $guardDeadline = $started + self::GUARD_MS * 1000000;
        $connection->beginTransaction();
        try {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->setAcquireBudget($connection, $acquireDeadline);
            $connection->statement('LOCK TABLE public.authorization_contexts IN SHARE MODE');
            $this->assertTopology($connection);
            $state = $this->lockReadSet($connection, $actor->id, $organizationId, $acquireDeadline);
            $freshActor = User::query()->find($actor->id);
            if (!$freshActor instanceof User || $generation !== $this->policyGeneration()) {
                throw new LogicException('authorization_changed');
            }
            $wallExpiry = $this->predicateExpiry($state);
            $remainingMs = min($this->remainingMs($guardDeadline, hrtime(true)), (int) floor(($wallExpiry - microtime(true)) * 1000));
            if ($remainingMs <= 0 || $this->remainingMs($acquireDeadline, hrtime(true)) <= 0) {
                throw new LogicException('authorization_changed');
            }
            $connection->selectOne("SELECT set_config('statement_timeout', ?, true)", [(string) $remainingMs], false);
            $allowed = $policy->withCurrentChecks($freshActor, $organizationId,
                fn (): bool => $policy->canReadDomain($freshActor, $organizationId, 'assistant'), true);
            if (!$allowed || $generation !== $this->policyGeneration()
                || hrtime(true) >= $guardDeadline || microtime(true) >= $wallExpiry) {
                throw new LogicException('authorization_changed');
            }
            $result = $inspect(['actorId' => $freshActor->id, 'organizationId' => $organizationId,
                'policyGeneration' => $generation, 'remainingMs' => min($this->remainingMs($guardDeadline, hrtime(true)),
                    (int) floor(($wallExpiry - microtime(true)) * 1000)), 'runtimeQualified' => false]);
            if ($generation !== $this->policyGeneration() || hrtime(true) >= $guardDeadline
                || microtime(true) >= $wallExpiry) {
                throw new LogicException('authorization_changed');
            }

            return $result;
        } catch (Throwable $error) {
            throw new LogicException('authorization_changed', 0, $error);
        } finally {
            $connection->rollBack();
        }
    }

    private function freshPolicy(LoggingService $logging): AssistantDataAccessPolicy
    {
        $scanner = new class extends RoleScanner {
            public function getRole(string $slug): ?array
            {
                return $this->getRoleUncached($slug);
            }
        };
        $entitlements = new OrganizationEntitlementService(new PackageCatalogService());
        $resolver = (new PermissionResolver($scanner, new ModulePermissionChecker(new AccessController()), $logging))
            ->withCurrentEntitlementSource($entitlements);

        return new AssistantDataAccessPolicy(new AuthorizationService($scanner, $resolver, $logging),
            new UserProjectAccessService(), $entitlements);
    }

    private function assertDatasource(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql' || $connection->transactionLevel() !== 0
            || $connection->getConfig('read') !== null || $connection->getConfig('write') !== null) {
            throw new LogicException('authorization_changed');
        }
        foreach ([new User(), new Organization(), new Module(), new UserRoleAssignment(), new RoleCondition(),
            new AuthorizationContext(), new OrganizationCustomRole(), new OrganizationPackageSubscription(),
            new OrganizationCommercialAccount(), new Project()] as $model) {
            if ($model->getConnection() !== $connection) {
                throw new LogicException('authorization_changed');
            }
        }
        $settings = $connection->selectOne("SELECT current_setting('server_version_num')::int AS version,
            current_setting('transaction_read_only') AS read_only, current_setting('session_replication_role') AS replica,
            current_schema() AS schema_name, pg_is_in_recovery() AS recovery", [], false);
        if ($settings === null || (int) $settings->version < 160000 || (int) $settings->version >= 170000
            || $settings->read_only !== 'off' || $settings->replica !== 'origin' || $settings->schema_name !== 'public'
            || $settings->recovery) {
            throw new LogicException('authorization_changed');
        }
    }

    private function assertTopology(Connection $connection): void
    {
        $foreignKeys = $connection->select("SELECT child.relname AS child_table, parent.relname AS parent_table,
            array_to_json(ARRAY(SELECT a.attname FROM unnest(c.conkey) WITH ORDINALITY k(id, n)
                JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.id ORDER BY k.n))::text AS child_columns,
            array_to_json(ARRAY(SELECT a.attname FROM unnest(c.confkey) WITH ORDINALITY k(id, n)
                JOIN pg_attribute a ON a.attrelid = c.confrelid AND a.attnum = k.id ORDER BY k.n))::text AS parent_columns,
            c.convalidated AND NOT c.condeferrable AND NOT c.condeferred
                AND child.relkind = 'r' AND parent.relkind = 'r'
                AND NOT EXISTS (SELECT 1 FROM pg_trigger t WHERE t.tgconstraint = c.oid AND t.tgenabled NOT IN ('O', 'A')) AS valid
            FROM pg_constraint c JOIN pg_class child ON child.oid = c.conrelid
            JOIN pg_class parent ON parent.oid = c.confrelid
            JOIN pg_namespace ns ON ns.oid = child.relnamespace
            JOIN pg_namespace pns ON pns.oid = parent.relnamespace
            WHERE c.contype = 'f' AND ns.nspname = 'public' AND pns.nspname = 'public'", [], false);
        $required = [
            ['organization_user', ['user_id'], 'users', ['id']],
            ['organization_user', ['organization_id'], 'organizations', ['id']],
            ['user_role_assignments', ['user_id'], 'users', ['id']],
            ['user_role_assignments', ['context_id'], 'authorization_contexts', ['id']],
            ['role_conditions', ['assignment_id'], 'user_role_assignments', ['id']],
            ['organization_custom_roles', ['organization_id'], 'organizations', ['id']],
            ['project_user', ['user_id'], 'users', ['id']],
            ['project_user', ['project_id'], 'projects', ['id']],
            ['organization_commercial_accounts', ['organization_id'], 'organizations', ['id']],
            ['organization_package_subscriptions', ['organization_id'], 'organizations', ['id']],
            ['organization_package_subscriptions', ['commercial_account_id', 'organization_id'],
                'organization_commercial_accounts', ['id', 'organization_id']],
        ];
        foreach ($required as [$child, $childColumns, $parent, $parentColumns]) {
            $matches = array_filter($foreignKeys, static fn (object $fk): bool => $fk->child_table === $child
                && $fk->parent_table === $parent && $fk->valid
                && json_decode($fk->child_columns, true, 8, JSON_THROW_ON_ERROR) === $childColumns
                && json_decode($fk->parent_columns, true, 8, JSON_THROW_ON_ERROR) === $parentColumns);
            if (count($matches) !== 1) {
                throw new LogicException('authorization_changed');
            }
        }
        $indexes = $connection->select("SELECT t.relname AS table_name,
            array_to_json(ARRAY(SELECT a.attname FROM unnest(i.indkey::smallint[]) WITH ORDINALITY k(id, n)
                JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.id
                WHERE k.n <= i.indnkeyatts ORDER BY k.n))::text AS columns
            FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid JOIN pg_namespace ns ON ns.oid = t.relnamespace
            WHERE ns.nspname = 'public' AND i.indisunique AND i.indisvalid AND i.indisready
                AND i.indpred IS NULL AND i.indexprs IS NULL AND i.indimmediate", [], false);
        foreach (['users' => ['id'], 'organizations' => ['id'], 'modules' => ['slug'],
            'organization_user' => ['user_id', 'organization_id'],
            'organization_custom_roles' => ['organization_id', 'slug'],
            'user_role_assignments' => ['user_id', 'role_slug', 'context_id'],
            'project_user' => ['project_id', 'user_id'],
            'organization_commercial_accounts' => ['organization_id'],
            'organization_package_subscriptions' => ['organization_id', 'package_slug']] as $table => $columns) {
            sort($columns);
            $matches = array_filter($indexes, static function (object $index) use ($table, $columns): bool {
                $actual = json_decode($index->columns, true, 8, JSON_THROW_ON_ERROR);
                sort($actual);

                return $index->table_name === $table && $actual === $columns;
            });
            if ($matches === []) {
                throw new LogicException('authorization_changed');
            }
        }
    }

    private function lockReadSet(Connection $connection, int $actorId, int $organizationId, int $deadline): array
    {
        $this->setAcquireBudget($connection, $deadline);
        $users = $connection->table('users')->where('id', $actorId)->lockForUpdate()->get();
        $organization = $connection->table('organizations')->where('id', $organizationId)->first();
        if ($users->count() !== 1 || $organization === null) {
            throw new LogicException('authorization_changed');
        }
        $organizationIds = array_values(array_unique(array_filter([$organizationId, $organization->parent_organization_id])));
        $this->setAcquireBudget($connection, $deadline);
        $organizations = $connection->table('organizations')->whereIn('id', $organizationIds)->orderBy('id')->lockForUpdate()->get();
        if ($organizations->count() !== count($organizationIds)
            || $organizations->firstWhere('id', $organizationId)->parent_organization_id !== $organization->parent_organization_id) {
            throw new LogicException('authorization_changed');
        }
        $this->setAcquireBudget($connection, $deadline);
        $connection->table('organization_user')->where('user_id', $actorId)->orderBy('organization_id')->lockForUpdate()->get();
        $this->setAcquireBudget($connection, $deadline);
        $assignments = $connection->table('user_role_assignments')->where('user_id', $actorId)->orderBy('id')->lockForUpdate()->get();
        $contexts = $connection->table('authorization_contexts')->whereIn('id', $assignments->pluck('context_id'))
            ->orWhere(static fn ($query) => $query->where('type', 'organization')->whereIn('resource_id', $organizationIds))->get()->keyBy('id');
        foreach ($organizationIds as $id) {
            if ($contexts->where('type', 'organization')->where('resource_id', $id)->count() > 1) {
                throw new LogicException('authorization_changed');
            }
        }
        foreach ($contexts->keys()->all() as $contextId) {
            $visited = [];
            while ($contextId !== null) {
                if (isset($visited[$contextId]) || count($visited) >= 64) {
                    throw new LogicException('authorization_changed');
                }
                $visited[$contextId] = true;
                $context = $contexts->get($contextId);
                if ($context === null) {
                    $context = $connection->table('authorization_contexts')->where('id', $contextId)->first();
                    if ($context === null) { throw new LogicException('authorization_changed'); }
                    $contexts->put($contextId, $context);
                }
                if ($context->type === 'organization' && !in_array((int) $context->resource_id, $organizationIds, true)) {
                    throw new LogicException('authorization_changed');
                }
                $contextId = $context->parent_context_id;
            }
        }
        foreach ($assignments as $assignment) {
            $context = $contexts->get($assignment->context_id);
            if ($context === null || !in_array($context->type, ['organization', 'project'], true)
                || ($context->type === 'project' && $contexts->get($context->parent_context_id)?->type !== 'organization')) {
                throw new LogicException('authorization_changed');
            }
        }
        $this->setAcquireBudget($connection, $deadline);
        $conditions = $connection->table('role_conditions')->whereIn('assignment_id', $assignments->pluck('id'))
            ->orderBy('id')->lockForUpdate()->get();
        if ($conditions->where('is_active', true)->contains('condition_type', 'location')) {
            throw new LogicException('authorization_changed');
        }
        $this->setAcquireBudget($connection, $deadline);
        $connection->table('organization_custom_roles')->whereIn('organization_id', $organizationIds)->orderBy('id')->lockForUpdate()->get();
        $this->setAcquireBudget($connection, $deadline);
        $module = $connection->table('modules')->where('slug', 'ai-assistant')->lockForUpdate()->get();
        if ($module->count() !== 1) { throw new LogicException('authorization_changed'); }
        $this->setAcquireBudget($connection, $deadline);
        $subscriptions = $connection->table('organization_package_subscriptions')->whereIn('organization_id', $organizationIds)
            ->orderBy('id')->lockForUpdate()->get();
        $this->setAcquireBudget($connection, $deadline);
        $accounts = $connection->table('organization_commercial_accounts')->whereIn('organization_id', $organizationIds)
            ->orderBy('id')->lockForUpdate()->get();
        if ($conditions->where('is_active', true)->contains('condition_type', 'project_count')) {
            $this->setAcquireBudget($connection, $deadline);
            $links = $connection->table('project_user')->where('user_id', $actorId)->orderBy('project_id')->lockForUpdate()->get();
            $this->setAcquireBudget($connection, $deadline);
            $connection->table('projects')->whereIn('id', $links->pluck('project_id'))->orderBy('id')->lockForUpdate()->get();
        }

        return compact('assignments', 'conditions', 'subscriptions', 'accounts');
    }

    private function predicateExpiry(array $state): float
    {
        $now = microtime(true);
        $expiry = $now + self::GUARD_MS / 1000;
        foreach (['assignments' => ['expires_at'], 'subscriptions' => ['trial_ends_at', 'current_period_end_at'],
            'accounts' => ['grace_ends_at']] as $key => $columns) {
            foreach ($state[$key] as $row) {
                foreach ($columns as $column) {
                    if ($row->{$column} !== null) {
                        $boundary = (float) CarbonImmutable::parse($row->{$column})->format('U.u');
                        if ($boundary > $now) { $expiry = min($expiry, $boundary); }
                        if ($key === 'subscriptions' && $column === 'current_period_end_at') {
                            $renewalBoundary = $boundary + max(0, (int) config('commercial_offers.renewal_processing_window_minutes', 5)) * 60;
                            if ($renewalBoundary > $now) { $expiry = min($expiry, $renewalBoundary); }
                        }
                    }
                }
            }
        }
        if ($state['conditions']->where('is_active', true)->contains('condition_type', 'time')) {
            $expiry = min($expiry, floor($now) + 1);
            foreach ($state['conditions']->where('is_active', true)->where('condition_type', 'time') as $condition) {
                $data = json_decode($condition->condition_data, true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($data)) { throw new LogicException('authorization_changed'); }
                foreach (['valid_from', 'valid_until'] as $key) {
                    if (isset($data[$key])) {
                        $boundary = (float) CarbonImmutable::parse($data[$key])->format('U.u');
                        if ($boundary > $now) { $expiry = min($expiry, $boundary); }
                    }
                }
            }
        }

        return $expiry;
    }

    private function policyGeneration(): string
    {
        $files = array_merge(glob(config_path('RoleDefinitions/*/*.json')) ?: [],
            glob(config_path('Packages/*.json')) ?: [], glob(config_path('ModuleList/*.json')) ?: []);
        sort($files);
        $hashes = [];
        foreach ($files as $file) {
            $digest = hash_file('sha256', $file);
            if ($digest === false) { throw new LogicException('authorization_changed'); }
            $hashes[$file] = $digest;
        }

        return hash('sha256', json_encode([$hashes, config('module_packages'), config('commercial_offers.renewal_processing_window_minutes', 5),
            config('app.timezone'), date_default_timezone_get()], JSON_THROW_ON_ERROR));
    }

    private function setAcquireBudget(Connection $connection, int $deadline): void
    {
        $remaining = $this->remainingMs($deadline, hrtime(true));
        if ($remaining <= 0) { throw new LogicException('authorization_changed'); }
        $connection->selectOne("SELECT set_config('lock_timeout', ?, true), set_config('statement_timeout', ?, true)",
            [(string) $remaining, (string) $remaining], false);
    }

    private function remainingMs(int $deadline, int $sampledNs): int
    {
        return (int) floor(($deadline - $sampledNs) / 1000000);
    }
}
