<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\RagExpectedSource;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\File;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\PostgresBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AssistantDataAccessPolicy
{
    private const DOMAINS = [
        'assistant' => ['ai-assistant', ['ai_assistant.chat']],
        'projects' => ['project-management', ['projects.view']],
        'contracts' => ['contract-management', ['contracts.view']],
        'estimates' => ['budget-estimates', ['budget-estimates.view']],
        'finance' => ['payments', ['finance.view', 'finance.view_project_budget', 'payments.dashboard.view', 'payments.invoice.view', 'payments.invoice.view_all']],
        'warehouse' => ['basic-warehouse', ['warehouse.view']],
        'materials' => ['catalog-management', ['materials.view']],
        'people' => ['users', ['users.view']],
        'procurement' => ['procurement', ['procurement.view', 'procurement.purchase_requests.view']],
        'schedule' => ['schedule-management', ['schedule.view']],
        'documents' => ['executive-documentation', ['executive-documentation.view']],
        'quality' => ['quality-control', ['quality-control.view']],
        'safety' => ['safety-management', ['safety-management.view']],
        'change_management' => ['change-management', ['change-management.view']],
        'handover_acceptance' => ['handover-acceptance', ['handover-acceptance.view']],
        'machinery' => ['machinery-operations', ['machinery-operations.view']],
        'production_labor' => ['production-labor', ['production-labor.view']],
        'site_requests' => ['site-requests', ['site_requests.view', 'site-requests.view']],
        'time_tracking' => ['time-tracking', ['time_tracking.view']],
        'crm' => ['crm', ['crm.view']],
        'commercial_processes' => ['commercial-proposals', ['commercial_proposals.view']],
        'reports' => ['reports', ['reports.view']],
        'contractors' => ['catalog-management', ['contractors.view']],
        'measurement_units' => ['catalog-management', ['measurement_units.view']],
        'knowledge' => ['knowledge-hub', ['system_admin.knowledge_hub.articles.view']],
        'design' => ['design-management', ['design-management.view']],
    ];
    private const SOURCE_DOMAINS = [
        'project' => 'projects', 'contract' => 'contracts', 'estimate' => 'estimates',
        'estimate_reference' => 'estimates', 'estimate_generation_learning' => 'estimates',
        'payment' => 'finance', 'performance_act' => 'contracts', 'work_completion' => 'projects',
        'project_pulse' => 'reports', 'warehouse' => 'warehouse', 'procurement' => 'procurement',
        'schedule' => 'schedule', 'site_request' => 'site_requests', 'construction_journal' => 'projects',
        'quality_executive_docs' => 'documents', 'safety' => 'safety', 'change_management' => 'change_management',
        'handover_acceptance' => 'handover_acceptance', 'machinery' => 'machinery', 'production_labor' => 'production_labor',
        'design' => 'design',
    ];
    private const PARENTS = [
        'estimate_item_resource' => ['item', 'estimate_item'], 'estimate_item' => ['estimate', 'estimate'],
        'estimate_section' => ['estimate', 'estimate'], 'schedule_task' => ['schedule', 'schedule'],
        'construction_journal_entry' => ['journal', 'construction_journal'],
        'crm_activity' => ['deal', 'crm_deal'],
        'performance_act' => ['contract', 'contract'], 'performance_act_line' => ['performanceAct', 'performance_act'],
    ];
    private const SECURITY_PARENT_COLUMNS = [
        'inventory_act' => ['warehouse_id' => ['type' => 'warehouse', 'nullable' => false]],
        'warehouse_storage_cell' => ['warehouse_id' => ['type' => 'warehouse', 'nullable' => false],
            'zone_id' => ['type' => 'warehouse_zone', 'nullable' => true, 'matches' => ['warehouse_id' => 'warehouse_id']]],
    ];
    private array $columns = [];
    private bool $schemaMetadataPrefetched = false;
    private array $entityQueryPath = [];
    private ?KnowledgeSurface $trustedSurface = null;

    public function setTrustedSurface(?KnowledgeSurface $surface): void
    {
        if ($surface === KnowledgeSurface::SUPERADMIN) {
            throw new \InvalidArgumentException('Unsupported assistant surface');
        }
        $this->trustedSurface = $surface;
    }
    private ?AssistantAclQueryCompiler $aclCompiler = null;
    private ?AuthorizationService $compiledAuthorization = null;
    private ?AuthorizationService $batchAuthorization = null;
    private ?array $batchIdentity = null;
    private array $batchDecisions = [];
    private array $batchEntityQueries = [];
    private ?\Closure $currentCheckpoint = null;

    public function withCurrentChecks(User $actor, int $organizationId, callable $operation, bool $fresh = false, ?callable $checkpoint = null): mixed
    {
        $identity = [(int) $actor->id, $organizationId];
        if (! $fresh && $this->batchIdentity === $identity) {
            $previousCheckpoint = $this->currentCheckpoint;
            if ($checkpoint !== null) { $this->currentCheckpoint = \Closure::fromCallable($checkpoint); }
            try {
                return $operation($this->batchAuthorization);
            } finally {
                $this->currentCheckpoint = $previousCheckpoint;
            }
        }
        if ($this->aclCompiler !== null) { throw new \LogicException('assistant_authorization_batch_during_query_compilation'); }
        $authorization = $this->authorization->forCurrentChecks(true);
        $previous = [$this->batchIdentity, $this->batchAuthorization, $this->batchDecisions, $this->batchEntityQueries, $this->currentCheckpoint];
        $this->batchIdentity = $identity;
        $this->batchAuthorization = $authorization;
        $this->batchDecisions = [];
        $this->batchEntityQueries = [];
        if ($checkpoint !== null) { $this->currentCheckpoint = \Closure::fromCallable($checkpoint); }
        try {
            return $operation($this->batchAuthorization);
        } finally {
            [$this->batchIdentity, $this->batchAuthorization, $this->batchDecisions, $this->batchEntityQueries, $this->currentCheckpoint] = $previous;
            if ($this->batchIdentity !== null) {
                $this->batchAuthorization = $this->authorization->forCurrentChecks(true);
                $this->batchDecisions = [];
                $this->batchEntityQueries = [];
            }
        }
    }

    public function canCurrentPermission(User $actor, int $organizationId, string $permission): bool
    {
        return $this->belongsToOrganization($actor, $organizationId)
            && $this->currentAuthorization()->canCurrent($actor, $permission, ['organization_id' => $organizationId]);
    }

    private function rememberCurrent(User $actor, int $organizationId, string $key, callable $resolve): mixed
    {
        $this->currentCheckpoint?->__invoke();
        if ($this->batchIdentity === [(int) $actor->id, $organizationId]) {
            if (! array_key_exists($key, $this->batchDecisions)) { $this->batchDecisions[$key] = $resolve(); }
            return $this->batchDecisions[$key];
        }
        return $this->aclCompiler === null ? $resolve() : $this->aclCompiler->remember($key, $resolve);
    }

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly UserProjectAccessService $projectAccess,
        private readonly ?\App\Services\Entitlements\OrganizationEntitlementService $modules = null,
    ) {}

    public function canReadSource(User $user, int $organizationId, array $source): bool
    {
        if (! $this->belongsToOrganization($user, $organizationId)) {
            return false;
        }
        if (isset($source['organization_id']) && (int) $source['organization_id'] !== $organizationId) {
            return false;
        }
        $type = (string) ($source['source_type'] ?? $source['sourceType'] ?? '');
        $entityType = (string) ($source['entity_type'] ?? $source['entityType'] ?? '');
        $entityId = $source['entity_id'] ?? $source['entityId'] ?? null;
        if ($entityId === null || $entityType === '') {
            return false;
        }
        if (! \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderSourceSchema::allowsSource($source)) { return false; }
        if ($type === 'file_document') {
            return $entityType === 'assistant_document' && $this->canReadEntity($user, $organizationId, $entityType, (string) $entityId);
        }
        if (! $this->canReadIndexedType($user, $organizationId, $type)) {
            return false;
        }
        $definition = $this->entities()[$entityType] ?? null;
        if ($definition === null || $definition[0] !== $type || in_array(AssistantExtendedDomainRegistry::retrievalMode($entityType), ['live_only', 'unavailable'], true)) {
            return false;
        }
        $projectId = $source['project_id'] ?? $source['projectId'] ?? null;
        if ($projectId !== null && ! $this->canReadEntity($user, $organizationId, 'project', (int) $projectId)) {
            return false;
        }
        return $this->canReadEntity($user, $organizationId, $entityType, (string) $entityId);
    }

    public function canReadEntity(User $user, int $organizationId, string $type, string|int $id): bool
    {
        if (! $this->belongsToOrganization($user, $organizationId)) {
            return false;
        }
        if ($type === 'assistant_document') {
            $document = AIAssistantDocument::query()->where('organization_id', $organizationId)->find($id);
            if ($document === null || ! $document->parent_entity_type || ! $document->parent_entity_id) {
                return false;
            }
            $native = \App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeDocumentRegistry::forDocument($document);
            if ($native !== null) {
                try {
                    $native->assertReadable($user, $organizationId, $document);
                    return true;
                } catch (\RuntimeException $exception) { $this->rethrowReadFailure($exception); return false; }
            }
            $file = $document->file_id === null ? null : File::query()->where('organization_id', $organizationId)->find($document->file_id);
            if ($file === null || $file->disk !== 's3' || $file->path !== $document->storage_path
                || $this->entityTypeForModel((string) $file->fileable_type) !== $document->parent_entity_type
                || (string) $file->fileable_id !== (string) $document->parent_entity_id) {
                return false;
            }
            if (! $this->currentNativeFile($user, $organizationId, $file)) { return false; }
            return $document->parent_entity_type !== 'assistant_document'
                && $this->canReadEntityContent($user, $organizationId, $document->parent_entity_type, (string) $document->parent_entity_id);
        }
        if ($type === 'file') {
            $file = File::query()->where('organization_id', $organizationId)->find($id);
            if ($file === null) {
                return false;
            }
            $operations = app(\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantOperationsNativeFileAdapter::class);
            if ($operations->isNativeFile($file)) {
                try { $operations->assertFileReadable($user, $organizationId, $file); return true; }
                catch (\RuntimeException $exception) { $this->rethrowReadFailure($exception); return false; }
            }
            if (! $this->currentNativeFile($user, $organizationId, $file)) { return false; }
            $type = $this->entityTypeForModel((string) $file->fileable_type);
            return $type !== null && $this->canReadEntityContent($user, $organizationId, $type, (string) $file->fileable_id);
        }
        $query = $this->entityQuery($user, $organizationId, $type);
        return $query !== null && $query->whereKey($id)->exists();
    }

    public function canReadEntityContent(User $user, int $organizationId, string $type, string|int $id): bool
    {
        if (in_array($type, \App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantLegalNativeFileMetadata::types(), true)) {
            return app(\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantLegalNativeFileAdapter::class)
                ->canReadNativeContent($user, $organizationId, $type, $id);
        }
        $definition = $this->entities()[$type] ?? null;
        return $definition !== null && $this->canReadIndexedType($user, $organizationId, $definition[0])
            && $this->canReadEntity($user, $organizationId, $type, $id);
    }

    public function canReadReference(User $user, int $organizationId, array $reference): bool
    {
        if (isset($reference['organization_id']) && (int) $reference['organization_id'] !== $organizationId) { return false; }
        $type = $reference['entity_type'] ?? $reference['entityType'] ?? $reference['type'] ?? null;
        $id = $reference['entity_id'] ?? $reference['entityId'] ?? $reference['id'] ?? null;
        if (! is_string($type) || (! is_string($id) && ! is_int($id))) { return false; }
        if (! \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderSourceSchema::allowsReference($type, $reference)) { return false; }
        if ($type === 'live_project_financial_projection') {
            return app(\App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantLiveProjectFinanceReader::class)
                ->matchesReference($user, $organizationId, $reference);
        }
        if ($type === 'published_report_financial_projection') {
            return app(\App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantPublishedReportReader::class)
                ->matchesReference($user, $organizationId, $reference);
        }
        if (isset($reference['source_id'])) {
            $source = RagSource::query()->where('organization_id', $organizationId)->find($reference['source_id']);
            if ($source === null || $source->entity_type !== $type || (string) $source->entity_id !== (string) $id
                || ! $this->canReadSource($user, $organizationId, $source->toArray())) { return false; }
        }
        if (isset($reference['projection_name'])) {
            return app(\App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantSalesParentProjection::class)
                ->canReadReference($user, $organizationId, $reference);
        }
        if (in_array($type, ['estimate', 'estimate_item', 'estimate_item_resource'], true)
            && ! app(\App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantEstimatePositionReadService::class)
                ->canReadReference($user, $organizationId, $reference)) {
            return false;
        }
        if (($reference['content_scope'] ?? null) === 'structured') {
            $fields = $reference['checked_fields'] ?? null;
            $permissions = $reference['required_permissions'] ?? null;
            $domains = $reference['required_domains'] ?? null;
            if (! is_array($fields) || $fields === [] || ! is_array($permissions) || ! is_array($domains) || $domains === []) { return false; }
            foreach ($permissions as $permission) {
                if (! is_string($permission) || ! $this->currentAuthorization()->canCurrent($user, $permission, ['organization_id' => $organizationId])) { return false; }
            }
            foreach ($domains as $domain) {
                if (! is_string($domain) || ! $this->canReadDomain($user, $organizationId, $domain)) { return false; }
            }
            return $this->canReadEntity($user, $organizationId, $type, $id);
        }
        if (isset($reference['source_type'])) {
            return $this->canReadSource($user, $organizationId, $reference + ['entity_type' => $type, 'entity_id' => $id]);
        }
        return in_array($type, ['assistant_document', 'file'], true) ? $this->canReadEntity($user, $organizationId, $type, $id)
            : $this->canReadEntityContent($user, $organizationId, $type, $id);
    }

    public function assertCanReadEntity(User $user, int $organizationId, string $type, string|int $id): void
    {
        if (! $this->canReadEntity($user, $organizationId, $type, $id)) {
            throw new AccessDeniedHttpException();
        }
    }

    public function belongsToOrganization(User $user, int $organizationId): bool
    {
        if ((int) $user->current_organization_id !== $organizationId || ! $user->is_active
            || ($this->aclCompiler !== null && ! $this->aclCompiler->accepts((int) $user->id, $organizationId))) { return false; }
        return $this->rememberCurrent($user, $organizationId, 'membership', fn (): bool => $this->checkOrganizationMembership($user, $organizationId));
    }

    private function checkOrganizationMembership(User $user, int $organizationId): bool
    {
        return $organizationId > 0 && (int) $user->current_organization_id === $organizationId
            && $user->is_active && User::query()->whereKey($user->id)->where('is_active', true)->where('current_organization_id', $organizationId)->exists()
            && $user->belongsToOrganization($organizationId);
    }

    public function canReadDomain(User $user, int $organizationId, string $domain): bool
    {
        if (! $this->belongsToOrganization($user, $organizationId)) { return false; }
        return $this->rememberCurrent($user, $organizationId, 'domain:'.$domain, fn (): bool => $this->checkDomainAccess($user, $organizationId, $domain));
    }

    private function checkDomainAccess(User $user, int $organizationId, string $domain): bool
    {
        if ($domain === 'knowledge') {
            return true;
        }
        $definition = (AssistantExtendedDomainRegistry::values('domainGates') + self::DOMAINS)[$domain] ?? null;
        if ($definition === null) {
            return false;
        }
        $moduleAlternatives = AssistantExtendedDomainRegistry::values('domainModuleAlternatives')[$domain] ?? [$definition[0]];
        $modules = $this->currentEffectiveModuleSlugs($user, $organizationId);
        if (! in_array('', $moduleAlternatives, true) && array_intersect($modules, $moduleAlternatives) === []) { return false; }
        if ($definition[1] === [] && (AssistantExtendedDomainRegistry::values('domainEntityPermissionGates')[$domain] ?? false) === true) { return true; }
        foreach ($definition[1] as $permission) {
            if ($this->currentAuthorization()->canCurrent($user, $permission, ['organization_id' => $organizationId])) {
                return true;
            }
        }
        return false;
    }

    public function effectiveModuleSlugs(User $user, int $organizationId): array
    {
        if (! $this->belongsToOrganization($user, $organizationId)) {
            return [];
        }

        return $this->currentEffectiveModuleSlugs($user, $organizationId);
    }

    public function allowedReportCodes(User $actor, int $organizationId, AuthorizationService $authorization): array
    {
        if (! $this->belongsToOrganization($actor, $organizationId)) { return []; }

        return $this->rememberCurrent($actor, $organizationId, 'report-definition-codes', static function () use ($actor, $organizationId, $authorization): array {
            $registry = app(\App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry::class);
            $modules = app(\App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionModuleAuthorizer::class)->decision($organizationId);
            $allowed = [];
            foreach ($registry->publishedCodes() as $code) {
                $definition = $registry->published($code)->definition;
                if (! $modules->allows($organizationId, $definition)) { continue; }
                $permissions = $definition->permissionPolicy->viewPermissions;
                if ($permissions === [] || array_filter($permissions, static fn (string $permission): bool => ! $authorization->canCurrent($actor, $permission, ['organization_id' => $organizationId])) !== []) { continue; }
                $allowed[] = $code;
            }

            return $allowed;
        });
    }

    private function currentEffectiveModuleSlugs(User $user, int $organizationId): array
    {
        $loadModules = fn (): array => ($this->modules ?? app(\App\Services\Entitlements\OrganizationEntitlementService::class))
            ->getEffectiveModules($organizationId)->pluck('slug')->all();

        $modules = $this->rememberCurrent($user, $organizationId, 'modules', $loadModules);

        return is_array($modules) ? $modules : [];
    }

    public function allowedSourceTypes(User $user, int $organizationId, ?array $candidates = null): array
    {
        if ($this->aclCompiler !== null) { return $this->currentAllowedSourceTypes($user, $organizationId, $candidates); }
        return $this->withCurrentChecks($user, $organizationId,
            fn (): array => $this->currentAllowedSourceTypes($user, $organizationId, $candidates));
    }

    public function trustedSurface(): ?KnowledgeSurface
    {
        return $this->trustedSurface;
    }

    private function currentAllowedSourceTypes(User $user, int $organizationId, ?array $candidates = null): array
    {
        if (! $this->belongsToOrganization($user, $organizationId)) {
            return [];
        }
        $types = $candidates === null || in_array('file_document', $candidates, true) ? ['file_document'] : [];
        $domainDecisions = [];
        foreach ($this->entities() as $definition) {
            $this->currentCheckpoint?->__invoke();
            if ($candidates !== null && ! in_array($definition[0], $candidates, true)) { continue; }
            $domain = $definition[2];
            $domainDecisions[$domain] ??= $this->canReadDomain($user, $organizationId, $domain);
            if ($domainDecisions[$domain] && $this->canReadIndexedType($user, $organizationId, $definition[0])) {
                $types[] = $definition[0];
            }
        }
        return array_values(array_unique($types));
    }

    public function applyToSources(Builder $query, User $user, int $organizationId): Builder
    {
        return $this->applySourceIdentityScope($query, $user, $organizationId, false);
    }

    public function sourceIdentityQueries(Builder $query, User $user, int $organizationId, ?callable $checkpoint = null, bool $expectedProjection = false): \Generator
    {
        if ($expectedProjection) { $this->assertExpectedSourceQuery($query); }
        $table = $query->getModel()->getTable();
        $candidates = (clone $query)->where($table.'.organization_id', $organizationId);
        if (! $expectedProjection) {
            \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderSourceSchema::apply($candidates, $table);
        }
        $checkpoint?->__invoke();
        $identityQuery = $candidates->toBase()->cloneWithout(['columns', 'orders', 'limit', 'offset'])
            ->select([$table.'.source_type', $table.'.entity_type'])->distinct();
        $identities = $this->finishAclDiscovery($candidates, $identityQuery)->get();
        foreach ($identities as $identity) {
            $checkpoint?->__invoke();
            $branch = (clone $query)->where($table.'.source_type', $identity->source_type)->where($table.'.entity_type', $identity->entity_type);
            if ($identity->source_type === 'file_document' && $identity->entity_type === 'assistant_document') {
                $documents = AIAssistantDocument::query()->where('organization_id', $organizationId)
                    ->whereIn(\Illuminate\Support\Facades\DB::raw('CAST(ai_assistant_documents.id AS TEXT)'),
                        (clone $branch)->where($table.'.organization_id', $organizationId)->select($table.'.entity_id'));
                $checkpoint?->__invoke();
                $parentTypes = (clone $documents)->select('parent_entity_type')->distinct()->toBase();
                foreach ($this->finishAclDiscovery($documents, $parentTypes)->pluck('parent_entity_type') as $parentType) {
                    $checkpoint?->__invoke();
                    $documentBranch = (clone $branch)->whereIn($table.'.entity_id', (clone $documents)
                        ->where('parent_entity_type', $parentType)->selectRaw('CAST(ai_assistant_documents.id AS TEXT)'));
                    yield $this->applySourceIdentityScope($documentBranch, $user, $organizationId, $expectedProjection, (string) $identity->source_type, (string) $identity->entity_type);
                }
            } else {
                yield $this->applySourceIdentityScope($branch, $user, $organizationId, $expectedProjection, (string) $identity->source_type, (string) $identity->entity_type);
            }
        }
    }

    public function aggregateSourceIdentities(Builder $query, User $user, int $organizationId, array $columns, callable $aggregate, ?callable $checkpoint = null, bool $expectedProjection = false): ?QueryBuilder
    {
        if ($expectedProjection) { $this->assertExpectedSourceQuery($query); }
        if ($this->aclCompiler !== null) { throw new \LogicException('assistant_source_aggregate_during_query_compilation'); }

        $scoped = $this->compileAcl($user, $organizationId, function () use ($query, $user, $organizationId, $columns, $aggregate, $checkpoint, $expectedProjection): ?Builder {
            $query = clone $query;
            $table = $query->getModel()->getTable();
            $query->where($table.'.organization_id', $organizationId);
            $projects = $this->entityQuery($user, $organizationId, 'project');
            $query->where(static function (Builder $scope) use ($projects, $table): void {
                $scope->where($table.'.source_type', 'file_document')->orWhereNull($table.'.project_id');
                if ($projects !== null) { $scope->orWhereIn($table.'.project_id', $projects->select('projects.id')); }
            });
            $candidateColumns = in_array($table.'.*', $columns, true) ? $columns : array_values(array_unique(array_merge($columns,
                array_map(static fn (string $column): string => $table.'.'.$column, ['id', 'organization_id', 'source_type', 'entity_type', 'entity_id', 'project_id']))));
            $query->select($candidateColumns);
            if (! $expectedProjection) {
                $revised = array_keys(\App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderSourceSchema::revisions());
                $guarded = (clone $query)->whereIn($table.'.entity_type', $revised);
                \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderSourceSchema::apply($guarded, $table);
                $query->whereNotIn($table.'.entity_type', $revised)->unionAll($guarded);
            }
            $candidates = $this->aclCompiler->register('__assistant_source_candidates', $query, [], materialize: false);
            $checkpoint?->__invoke();
            $visible = $this->applySourceIdentityScope($candidates, $user, $organizationId, $expectedProjection, preparedCandidates: true, splitIdentities: true)
                ->select($columns)->toBase();
            $checkpoint?->__invoke();

            return $query->getModel()->newQueryWithoutScopes()->fromSub($aggregate($visible), $table)->select($table.'.*');
        }, compact: true);

        return $scoped?->toBase();
    }

    public function applyToExpectedSources(Builder $query, User $user, int $organizationId): Builder
    {
        $this->assertExpectedSourceQuery($query);

        return $this->applySourceIdentityScope($query, $user, $organizationId, true);
    }

    private function assertExpectedSourceQuery(Builder $query): void
    {
        if (! $query->getModel() instanceof RagExpectedSource
            || $query->getModel()->getTable() !== 'ai_rag_expected_sources'
            || $query->getQuery()->from !== 'ai_rag_expected_sources') {
            throw new \InvalidArgumentException('assistant_expected_projection_model_required');
        }
    }

    private function applySourceIdentityScope(Builder $query, User $user, int $organizationId, bool $expectedProjection, ?string $knownSourceType = null, ?string $knownEntityType = null, bool $preparedCandidates = false, bool $splitIdentities = false): Builder
    {
        if ($this->aclCompiler === null) {
            if ($preparedCandidates) { throw new \LogicException('assistant_prepared_candidates_require_query_compilation'); }
            return $this->compileAcl($user, $organizationId, fn (): Builder => $this->applySourceIdentityScope($query, $user, $organizationId, $expectedProjection, $knownSourceType, $knownEntityType)) ?? $query->whereRaw('1 = 0');
        }
        $table = $query->getModel()->getTable();
        $query->where($table.'.organization_id', $organizationId);
        if (! $this->belongsToOrganization($user, $organizationId)) {
            return $query->whereRaw('1 = 0');
        }
        if (! $expectedProjection && ! $preparedCandidates) {
            \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderSourceSchema::apply($query, $table);
        }
        $sourceIdentities = [];
        if ($knownSourceType !== null && $knownEntityType !== null) {
            $query->where($table.'.source_type', $knownSourceType)->where($table.'.entity_type', $knownEntityType);
            $sourceIdentities[$knownEntityType][$knownSourceType] = true;
        } else {
            $identityQuery = $query->toBase()->cloneWithout(['columns', 'orders', 'limit', 'offset'])
                ->select([$table.'.source_type', $table.'.entity_type'])->distinct();
            foreach ($this->finishAclDiscovery($query, $identityQuery)->get() as $identity) {
                $sourceIdentities[(string) $identity->entity_type][(string) $identity->source_type] = true;
            }
        }
        if ($splitIdentities) {
            $union = null;
            foreach ($this->entities() as $entityType => $definition) {
                if (! isset($sourceIdentities[$entityType][$definition[0]])
                    || in_array(AssistantExtendedDomainRegistry::retrievalMode($entityType), ['live_only', 'unavailable'], true)) { continue; }
                $entities = $this->canReadIndexedType($user, $organizationId, $definition[0]) ? $this->entityQuery($user, $organizationId, $entityType) : null;
                if ($entities === null) { continue; }
                $branch = $entities->select([])->selectRaw('? AS source_type, ? AS entity_type, CAST('.$entities->getModel()->getQualifiedKeyName().' AS TEXT) AS entity_id',
                    [$definition[0], $entityType])->toBase();
                if ($union === null) { $union = $branch; } else { $union->unionAll($branch); }
            }
            if (isset($sourceIdentities['assistant_document']['file_document'])) {
                $documentCandidates = AIAssistantDocument::query()->where('organization_id', $organizationId)
                    ->whereIn(\Illuminate\Support\Facades\DB::raw('CAST(ai_assistant_documents.id AS TEXT)'),
                        (clone $query)->where($table.'.source_type', 'file_document')->where($table.'.entity_type', 'assistant_document')->select($table.'.entity_id'));
                $branch = $this->accessibleDocuments($user, $organizationId, $documentCandidates)->select([])
                    ->selectRaw('? AS source_type, ? AS entity_type, CAST(ai_assistant_documents.id AS TEXT) AS entity_id', ['file_document', 'assistant_document'])->toBase();
                if ($union === null) { $union = $branch; } else { $union->unionAll($branch); }
            }

            return $union === null ? $query->whereRaw('1 = 0')
                : $query->whereIn(\Illuminate\Support\Facades\DB::raw('('.$table.'.source_type, '.$table.'.entity_type, '.$table.'.entity_id)'), $union);
        }
        return $query->where(function (Builder $scope) use ($user, $organizationId, $table, $sourceIdentities, $query): void {
            $matched = false;
            foreach ($this->entities() as $type => $definition) {
                if (! isset($sourceIdentities[$type][$definition[0]])) { continue; }
                if (in_array(AssistantExtendedDomainRegistry::retrievalMode($type), ['live_only', 'unavailable'], true)) { continue; }
                $entities = $this->canReadIndexedType($user, $organizationId, $definition[0]) ? $this->entityQuery($user, $organizationId, $type) : null;
                if ($entities !== null) {
                    $matched = true;
                    $entityKey = $entities->getModel()->getQualifiedKeyName();
                    $scope->orWhere(function (Builder $branch) use ($type, $definition, $entities, $entityKey, $table): void {
                        $branch->where($table.'.source_type', $definition[0])->where($table.'.entity_type', $type)
                            ->whereIn($table.'.entity_id', $entities->select([])->selectRaw('CAST('.$entityKey.' AS TEXT)'));
                    });
                }
            }
            if (isset($sourceIdentities['assistant_document']['file_document'])) {
                $matched = true;
                $documentCandidates = AIAssistantDocument::query()->where('organization_id', $organizationId)
                    ->whereIn(\Illuminate\Support\Facades\DB::raw('CAST(ai_assistant_documents.id AS TEXT)'),
                        (clone $query)->where($table.'.source_type', 'file_document')->where($table.'.entity_type', 'assistant_document')->select($table.'.entity_id'));
                $documents = $this->accessibleDocuments($user, $organizationId, $documentCandidates);
                $scope->orWhere(function (Builder $branch) use ($documents, $table): void {
                    $branch->where($table.'.source_type', 'file_document')->where($table.'.entity_type', 'assistant_document')
                        ->whereIn($table.'.entity_id', $documents->select([])->selectRaw('CAST(ai_assistant_documents.id AS TEXT)'));
                });
            }
            if (! $matched) { $scope->whereRaw('1 = 0'); }
        });
    }

    public function organizationWideSourceTypes(): array
    {
        return ['estimate_reference', 'estimate_generation_learning', 'file_document', 'warehouse', 'knowledge', 'people', 'crm', 'commercial_processes'];
    }

    public function scopeTable(QueryBuilder $query, User $user, int $organizationId, string $table, bool $content = false): QueryBuilder
    {
        foreach ($this->entities() as $type => $definition) {
            $model = new $definition[1];
            if ($model->getTable() !== $table) {
                continue;
            }
            $entities = $content && ! $this->canReadIndexedType($user, $organizationId, $definition[0]) ? null : $this->entityQuery($user, $organizationId, $type);
            return $entities === null ? $query->whereRaw('1 = 0') : $query->whereIn($model->getQualifiedKeyName(), $entities->select($model->getQualifiedKeyName()));
        }
        return $query->whereRaw('1 = 0');
    }

    public function entityContentQuery(User $user, int $organizationId, string $type): ?Builder
    {
        $definition = $this->entities()[$type] ?? null;
        if ($definition === null) { return null; }
        foreach (\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeFileRegistry::permissions($type) as $permission) {
            if (! $this->currentAuthorization()->canCurrent($user, $permission, ['organization_id' => $organizationId])) { return null; }
        }
        $legalNative = in_array($type, \App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantLegalNativeFileMetadata::types(), true);
        return $legalNative || $this->canReadIndexedType($user, $organizationId, $definition[0])
            ? $this->entityQuery($user, $organizationId, $type) : null;
    }

    public function accessibleFiles(User $user, int $organizationId, bool $supportedStorageOnly = true): Builder
    {
        if ($this->aclCompiler === null) {
            return $this->compileAcl($user, $organizationId, fn (): Builder => $this->accessibleFiles($user, $organizationId, $supportedStorageOnly)) ?? File::query()->whereRaw('1 = 0');
        }
        $query = File::query()->where('files.organization_id', $organizationId);
        if ($supportedStorageOnly) { $query->where('files.disk', 's3'); }
        if (! $this->belongsToOrganization($user, $organizationId)) { return $query->whereRaw('1 = 0'); }
        $fileTypeQuery = (clone $query)->select('files.fileable_type')->distinct()->toBase();
        $fileTypes = $this->finishAclDiscovery($query, $fileTypeQuery)->pluck('fileable_type')->all();
        $fileClasses = array_map(static fn ($type): string => \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel((string) $type) ?? (string) $type, $fileTypes);
        app(\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantOperationsNativeFileAdapter::class)->applyFileScope($query, $user, $organizationId, $this);
        return $query->where(function (Builder $files) use ($user, $organizationId, $fileClasses): void {
            $files->whereRaw('1 = 0');
            $operations = app(\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantOperationsNativeFileAdapter::class);
            foreach (['safety_medical_exam', 'warehouse_item_gallery'] as $nativeType) {
                $nativeFiles = $operations->sourceQueryForActor($user, $organizationId, $nativeType)
                    ->select($nativeType === 'safety_medical_exam' ? 'native_file.id' : 'native_source.id');
                $files->orWhereIn('files.id', $nativeFiles);
            }
            foreach ($this->entities() as $type => $definition) {
                if (! in_array($definition[1], $fileClasses, true)) { continue; }
                $entities = $this->entityContentQuery($user, $organizationId, $type);
                if ($entities === null) { continue; }
                $parent = $entities->getModel();
                $files->orWhere(static function (Builder $branch) use ($entities, $parent, $definition, $type): void {
                    $branch->whereIn('files.fileable_type', [$definition[1], $parent->getMorphClass()])
                        ->whereIn(\Illuminate\Support\Facades\DB::raw('CAST(files.fileable_id AS TEXT)'), $entities->select([])->selectRaw('CAST('.$parent->getQualifiedKeyName().' AS TEXT)'));
                    if ($type === 'design_artifact_version') {
                        $branch->whereExists(static function (QueryBuilder $version): void {
                            $version->selectRaw('1')->from('design_artifact_versions')->whereColumn('design_artifact_versions.id', 'files.fileable_id')
                                ->whereColumn('design_artifact_versions.organization_id', 'files.organization_id')
                                ->whereColumn('design_artifact_versions.source_file_path', 'files.path')
                                ->whereColumn('design_artifact_versions.source_original_name', 'files.original_name')
                                ->whereColumn('design_artifact_versions.source_mime_type', 'files.mime_type')
                                ->whereColumn('design_artifact_versions.source_size_bytes', 'files.size')
                                ->whereRaw("COALESCE(files.additional_info->>'design_source_sha256', '') = COALESCE(design_artifact_versions.source_sha256, '')")
                                ->whereRaw("files.additional_info->>'assistant_native_source' = 'design'");
                        });
                    }
                    if (\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeFileRegistry::supports($type)) {
                        \App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeFileRegistry::adapter($type)?->constrainMappings($branch);
                    }
                });
            }
        });
    }

    public function accessibleDocuments(User $user, int $organizationId, ?Builder $candidates = null): Builder
    {
        if ($this->aclCompiler === null) {
            return $this->compileAcl($user, $organizationId, fn (): Builder => $this->accessibleDocuments($user, $organizationId, $candidates)) ?? AIAssistantDocument::query()->whereRaw('1 = 0');
        }
        $query = ($candidates === null ? AIAssistantDocument::query() : clone $candidates)->where('organization_id', $organizationId);
        if (! $this->belongsToOrganization($user, $organizationId)) { return $query->whereRaw('1 = 0'); }
        if (! Schema::hasColumn('ai_assistant_documents', 'file_id')) {
            return $query->whereRaw('1 = 0');
        }
        $documentTypeQuery = (clone $query)->select('parent_entity_type')->distinct()->toBase();
        $documentTypes = $this->finishAclDiscovery($query, $documentTypeQuery)->pluck('parent_entity_type')->all();
        return $query->where(function (Builder $parents) use ($user, $organizationId, $documentTypes): void {
            $parents->whereRaw('1 = 0');
            if (array_intersect($documentTypes, \App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantOperationsNativeFileMetadata::types()) !== []) {
                $parents->orWhere(function (Builder $native) use ($user, $organizationId): void {
                $operations = app(\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantOperationsNativeFileAdapter::class);
                $operations->constrainDocuments($native);
                $operations->applyDocumentScope($native, $user, $organizationId, $this);
                });
            }
            foreach ($this->entities() as $type => $definition) {
                if (! in_array($type, $documentTypes, true)) { continue; }
                $entities = $this->entityContentQuery($user, $organizationId, $type);
                if ($entities === null) {
                    continue;
                }
                if (isset(\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantSalesNativeFileMetadata::definitions()[$type])) {
                    $nativeEntities = clone $entities;
                    $parents->orWhere(function (Builder $native) use ($type, $nativeEntities): void {
                        $native->whereNull('file_id')->where('parent_entity_type', $type)
                            ->whereIn('parent_entity_id', $nativeEntities->select([])->selectRaw('CAST('.$nativeEntities->getModel()->getQualifiedKeyName().' AS TEXT)'));
                        app(\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantSalesNativeFileAdapter::class)->constrainDocuments($native);
                    });
                }
                $parents->orWhere(function (Builder $branch) use ($type, $definition, $entities, $organizationId): void {
                    $branch->where('parent_entity_type', $type)->whereIn('parent_entity_id', $entities->select([])->selectRaw('CAST('.$entities->getModel()->getQualifiedKeyName().' AS TEXT)'))
                        ->whereExists(function (QueryBuilder $file) use ($definition, $organizationId, $type): void {
                            $model = new $definition[1];
                            $file->selectRaw('1')->from('files')->whereColumn('files.id', 'ai_assistant_documents.file_id')
                                ->where('files.organization_id', $organizationId)->whereNull('files.deleted_at')->where('files.disk', 's3')
                                ->whereColumn('files.path', 'ai_assistant_documents.storage_path')
                                ->whereRaw('CAST(files.fileable_id AS TEXT) = ai_assistant_documents.parent_entity_id')
                                ->whereIn('files.fileable_type', [$definition[1], $model->getMorphClass()]);
                            if ($type === 'design_artifact_version') {
                                $file->whereExists(static function (QueryBuilder $version): void {
                                    $version->selectRaw('1')->from('design_artifact_versions')->whereColumn('design_artifact_versions.id', 'files.fileable_id')
                                        ->whereColumn('design_artifact_versions.organization_id', 'files.organization_id')
                                        ->whereColumn('design_artifact_versions.source_file_path', 'files.path')
                                        ->whereColumn('design_artifact_versions.source_original_name', 'files.original_name')
                                        ->whereColumn('design_artifact_versions.source_mime_type', 'files.mime_type')
                                        ->whereColumn('design_artifact_versions.source_size_bytes', 'files.size')
                                        ->whereRaw("COALESCE(files.additional_info->>'design_source_sha256', '') = COALESCE(design_artifact_versions.source_sha256, '')")
                                        ->whereRaw("files.additional_info->>'assistant_native_source' = 'design'");
                                });
                            }
                            if (\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeFileRegistry::supports($type)) {
                                \App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeFileRegistry::adapter($type)?->constrainMappings($file);
                            }
                        });
                });
            }
        });
    }

    public function entityQuery(User $user, int $organizationId, string $type): ?Builder
    {
        $this->currentCheckpoint?->__invoke();
        if ($this->aclCompiler !== null && ! $this->aclCompiler->accepts((int) $user->id, $organizationId)) { return null; }
        if (AssistantExtendedDomainRegistry::retrievalMode($type) === 'unavailable') { return null; }
        if (count($this->entityQueryPath) >= 16 || in_array($type, $this->entityQueryPath, true)) { return null; }
        if ($this->aclCompiler !== null && $this->aclCompiler->has($type)) { return $this->aclCompiler->reference($type, $this->entityQueryPath); }
        $templateKey = null;
        if ($this->aclCompiler === null && $this->entityQueryPath === [] && $this->batchIdentity === [(int) $user->id, $organizationId]) {
            $surface = $this->trustedSurface ?? (request()->is('api/v1/mobile/*') ? KnowledgeSurface::MOBILE
                : (request()->is('api/v1/admin/*') ? KnowledgeSurface::ADMIN : KnowledgeSurface::LK));
            $templateKey = implode(':', [(int) $user->id, $organizationId, $surface->value, $type]);
            if (isset($this->batchEntityQueries[$templateKey])) {
                return $this->belongsToOrganization($user, $organizationId) ? clone $this->batchEntityQueries[$templateKey] : null;
            }
        }
        if ($this->aclCompiler === null) {
            $query = $this->compileAcl($user, $organizationId, fn (): ?Builder => $this->entityQuery($user, $organizationId, $type));
            if ($templateKey !== null && $query !== null) { $this->batchEntityQueries[$templateKey] = clone $query; }

            return $query;
        }
        $this->entityQueryPath[] = $type;
        try {
            $query = $this->buildEntityQuery($user, $organizationId, $type);
            if ($query === null) { return null; }
            $table = $query->getModel()->getTable();
            $columns = $this->schemaColumns($table);
            $internal = [$query->getModel()->getKeyName()];
            foreach (AssistantExtendedDomainRegistry::values('parentColumns') + self::SECURITY_PARENT_COLUMNS as $parents) {
                foreach ($parents as $parent) {
                    if ($parent['type'] !== $type) { continue; }
                    $internal[] = $parent['key'] ?? 'id';
                    array_push($internal, ...array_keys($parent['matches'] ?? []));
                    if ($parent['match_project'] ?? false) { $internal[] = 'project_id'; }
                }
            }
            foreach (AssistantExtendedDomainRegistry::values('parentProjectionDefinitions')[$type] ?? [] as $projection) {
                $internal[] = $projection['parent_key'] ?? $query->getModel()->getKeyName();
                array_push($internal, ...array_values($projection['matches'] ?? []));
            }
            if (in_array('deleted_at', $columns, true)) { $internal[] = 'deleted_at'; }
            if (in_array('project_id', $columns, true)) { $internal[] = 'project_id'; }
            if (in_array('organization_id', $columns, true)) { $internal[] = 'organization_id'; }

            return $this->aclCompiler->register($type, $query, array_values(array_intersect(array_unique($internal), $columns)), $this->entityQueryPath);
        } finally {
            array_pop($this->entityQueryPath);
        }
    }

    private function finishAclDiscovery(Builder $query, QueryBuilder $discovery): QueryBuilder
    {
        if ($this->aclCompiler === null) { return $discovery; }
        $table = $query->getModel()->getTable();
        $wrapped = $query->getModel()->newQueryWithoutScopes()->fromSub($discovery, $table)->select($table.'.*');

        return $this->aclCompiler->finish($wrapped)->toBase();
    }

    private function compileAcl(User $user, int $organizationId, callable $callback, bool $compact = false): ?Builder
    {
        $this->currentCheckpoint?->__invoke();
        $compiler = new AssistantAclQueryCompiler((int) $user->id, $organizationId, $compact);
        $this->aclCompiler = $compiler;
        $this->compiledAuthorization = $this->batchAuthorization ?? $this->authorization->forCurrentChecks(true);
        try {
            $query = $callback();
            return $query === null ? null : $compiler->finish($query);
        } finally {
            $this->aclCompiler = null;
            $this->compiledAuthorization = null;
        }
    }

    private function currentAuthorization(): AuthorizationService
    {
        return $this->compiledAuthorization ?? $this->batchAuthorization ?? $this->authorization;
    }

    public function accessibleProjects(User $user, int $organizationId): Builder
    {
        if (! $this->belongsToOrganization($user, $organizationId)) { return Project::query()->whereRaw('1 = 0'); }
        $load = fn (): Builder => $this->projectAccess->queryAccessibleProjects($user, $organizationId);
        $query = $this->rememberCurrent($user, $organizationId, 'projects', $load);
        if ($this->aclCompiler === null) { return clone $query; }
        if (! $this->aclCompiler->accepts((int) $user->id, $organizationId)) { return Project::query()->whereRaw('1 = 0'); }
        if ($this->aclCompiler->has('__assistant_project_visibility')) {
            $query = $this->aclCompiler->reference('__assistant_project_visibility') ?? Project::query()->whereRaw('1 = 0');
        } else {
            $query = $this->aclCompiler->register('__assistant_project_visibility', clone $query, []);
        }
        $query->getQuery()->columns = null;

        return $query;
    }

    private function schemaColumns(string $table): array
    {
        $this->currentCheckpoint?->__invoke();
        if (isset($this->columns[$table])) { return $this->columns[$table]; }
        $load = static fn (): array => Schema::getColumnListing($table);
        $columns = $this->aclCompiler === null ? $load() : $this->aclCompiler->remember('schema:'.$table, $load);
        if ($columns !== []) { $this->columns[$table] = $columns; }

        return $columns;
    }

    public function prefetchEntitySchemaMetadata(?callable $checkpoint = null, ?callable $checkDeadline = null): void
    {
        if ($this->schemaMetadataPrefetched || DB::connection()->getDriverName() !== 'pgsql') { return; }
        if ($checkDeadline !== null) { $checkDeadline(); }

        $connection = DB::connection();
        $schemaBuilder = $connection->getSchemaBuilder();
        if (! $schemaBuilder instanceof PostgresBuilder) { return; }

        $grammar = $connection->getSchemaGrammar();
        $pairTables = [];
        foreach (self::entityDefinitions() as $definition) {
            $modelClass = $definition[1] ?? null;
            if (! is_string($modelClass) || ! class_exists($modelClass)) { continue; }

            $model = (new \ReflectionClass($modelClass))->newInstanceWithoutConstructor();
            if (! $model instanceof Model) { continue; }
            $table = $model->getTable();
            [$schema, $relation] = $this->schemaMetadataTableReference($schemaBuilder, $connection, $table);
            $grammar->compileColumns($schema, $relation);
            $pairTables[$schema."\0".$relation][$table] = true;
        }

        $values = [];
        $bindings = [];
        foreach ($pairTables as $key => $_tables) {
            [$schema, $relation] = explode("\0", $key, 2);
            $values[] = '(?, ?)';
            $bindings[] = $schema;
            $bindings[] = $relation;
        }

        $columnsByPair = [];
        if ($values !== []) {
            if ($checkpoint !== null) { $checkpoint(); }
            $sql = 'select n.nspname as schema_name, c.relname as table_name, a.attname as column_name, a.attnum as ordinal_position '
                .'from pg_attribute a join pg_class c on c.oid = a.attrelid join pg_type t on t.oid = a.atttypid '
                .'join pg_namespace n on n.oid = c.relnamespace where a.attnum > 0 '
                .'and exists (select 1 from (values '.implode(',', $values).') as target(schema_name, relation_name) '
                .'where target.schema_name::text = n.nspname::text and target.relation_name::text = c.relname::text) '
                .'order by n.nspname, c.relname, a.attnum';

            foreach ($connection->selectFromWriteConnection($sql, $bindings) as $row) {
                $key = $row->schema_name."\0".$row->table_name;
                $columnsByPair[$key][] = (string) $row->column_name;
            }
        }
        if ($checkDeadline !== null) { $checkDeadline(); }

        $columns = [];
        foreach ($pairTables as $key => $tables) {
            foreach (array_keys($tables) as $table) { $columns[$table] = $columnsByPair[$key] ?? []; }
        }
        $this->columns = array_replace($this->columns, $columns);
        $this->schemaMetadataPrefetched = true;
    }

    private function schemaMetadataTableReference(PostgresBuilder $schemaBuilder, \Illuminate\Database\Connection $connection, string $table): array
    {
        [$schema, $relation] = $schemaBuilder->parseSchemaAndTable($table);

        return [$schema, $connection->getTablePrefix().$relation];
    }

    private function buildEntityQuery(User $user, int $organizationId, string $type, bool $skipBusinessReferences = false): ?Builder
    {
        $definition = $this->entities()[$type] ?? null;
        if ($definition === null || ! $this->canReadDomain($user, $organizationId, $definition[2])) {
            return null;
        }
        $entityPermission = match ($type) {
            'crm_deal' => 'crm.deals.view', 'crm_lead' => 'crm.leads.view',
            'crm_company' => 'crm.companies.view', 'crm_contact' => 'crm.contacts.view',
            'crm_activity' => 'crm.activities.view', 'performance_act', 'performance_act_line' => 'contracts.performance_acts.view',
            'completed_work' => 'contracts.completed_works.view',
            'design_review_comment' => 'design-management.review', 'design_model_set' => 'design-management.models.view',
            'workforce_export_package_file' => 'workforce.exports.generate',
            'purchase_request' => 'procurement.purchase_requests.view', 'purchase_order', 'purchase_receipt' => 'procurement.purchase_orders.view',
            'supplier_request' => 'procurement.supplier_requests.view', 'supplier_proposal' => 'procurement.supplier_proposals.view',
            'supplier_proposal_decision' => 'procurement.proposal_decisions.view', 'procurement_approval' => 'procurement.approvals.view',
            'procurement_audit_event' => 'procurement.audit.view', default => null,
        };
        if ($type === 'payment_document' && ! $this->currentAuthorization()->canCurrent($user, 'payments.invoice.view', ['organization_id' => $organizationId])
            && ! $this->currentAuthorization()->canCurrent($user, 'payments.invoice.view_all', ['organization_id' => $organizationId])) { return null; }
        if ($entityPermission !== null && ! $this->currentAuthorization()->canCurrent($user, $entityPermission, ['organization_id' => $organizationId])) {
            return null;
        }
        foreach (AssistantExtendedDomainRegistry::values('entityPermissions')[$type] ?? [] as $permission) {
            if (! $this->currentAuthorization()->canCurrent($user, $permission, ['organization_id' => $organizationId])) { return null; }
        }
        $model = new $definition[1];
        $table = $model->getTable();
        $columns = $this->schemaColumns($table);
        if ($columns === []) { return null; }
        if ($type === 'project') {
            return $this->accessibleProjects($user, $organizationId);
        }
        $aggregate = AssistantExtendedDomainRegistry::values('organizationAggregates')[$type] ?? false;
        $restrictedAggregate = $aggregate && $this->rememberCurrent($user, $organizationId, 'restricted-project-scope',
            function () use ($user, $organizationId): bool {
                $projects = Project::query()->where('organization_id', $organizationId)->whereNotIn('id',
                    $this->accessibleProjects($user, $organizationId)->select('projects.id'));

                return $this->finishAclDiscovery($projects, $projects->toBase())->exists();
            });
        if ($restrictedAggregate && $aggregate === true) { return null; }
        if ($type === 'user') {
            return User::query()->where('users.is_active', true)->whereHas('organizations', static fn (Builder $organizations): Builder => $organizations->where('organizations.id', $organizationId)->where('organization_user.is_active', true));
        }
        if ($type === 'knowledge_article') {
            $surface = $this->trustedSurface ?? (request()->is('api/v1/mobile/*') ? KnowledgeSurface::MOBILE
                : (request()->is('api/v1/admin/*') ? KnowledgeSurface::ADMIN : KnowledgeSurface::LK));
            $context = $this->rememberCurrent($user, $organizationId, 'knowledge_article_context:'.$surface->value, function () use ($user, $organizationId, $surface): \App\BusinessModules\Features\KnowledgeHub\DTOs\KnowledgeAccessContext {
                $authorization = $this->currentAuthorization();
                try {
                    $rawPermissionKeys = $authorization->getUserPermissions($user);
                } catch (\Throwable) {
                    $rawPermissionKeys = [];
                }
                $permissionKeys = collect($rawPermissionKeys)
                    ->filter(static fn (mixed $permission): bool => is_string($permission) && trim($permission) !== '')
                    ->map(static fn (string $permission): string => trim($permission))
                    ->unique()
                    ->values()
                    ->all();
                $permissionKeys = array_values(array_filter($permissionKeys,
                    fn (string $permission): bool => $authorization->canCurrent($user, $permission, ['organization_id' => $organizationId])));
                $moduleSlugs = ($this->modules ?? app(\App\Services\Entitlements\OrganizationEntitlementService::class))
                    ->getEffectiveModules($organizationId)->pluck('slug')->all();
                $audiences = ['all'];
                if ($surface === KnowledgeSurface::ADMIN) { $audiences[] = 'admin'; }
                $authContext = $this->rememberCurrent($user, $organizationId, 'knowledge_article_organization_context',
                    fn (): ?\App\Domain\Authorization\Models\AuthorizationContext => \App\Domain\Authorization\Models\AuthorizationContext::query()
                        ->where('type', 'organization')->where('resource_id', $organizationId)->first());
                $roles = $authContext === null ? collect() : $authorization->getUserRoles($user, $authContext);
                foreach ($roles as $role) {
                    $slug = str_replace('-', '_', strtolower($role->role_slug));
                    $audience = match (true) {
                        str_contains($slug, 'owner') => 'owner', str_contains($slug, 'admin') => 'admin',
                        str_contains($slug, 'manager') => 'manager', str_contains($slug, 'foreman') || str_contains($slug, 'master') => 'foreman',
                        str_contains($slug, 'worker') => 'worker', str_contains($slug, 'contractor') => 'contractor',
                        str_contains($slug, 'accountant') || str_contains($slug, 'finance') => 'accountant', default => null,
                    };
                    if ($audience !== null) { $audiences[] = $audience; }
                }
                return new \App\BusinessModules\Features\KnowledgeHub\DTOs\KnowledgeAccessContext(
                    $surface, array_values(array_unique($audiences)), $permissionKeys, $moduleSlugs, null, null, null, (int) $user->id, $organizationId);
            });
            return app(\App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeAccessFilter::class)->apply($model->newQuery()->where('status', 'published'), $context);
        }
        $query = $model->newQuery();
        if ($restrictedAggregate && is_string($aggregate)) { $query->whereNotNull($table.'.'.$aggregate); }
        $safeColumns = AssistantExtendedDomainRegistry::values('safeSelectColumns')[$type] ?? null;
        if (is_array($safeColumns)) {
            $safeColumns = array_values(array_intersect($safeColumns, $columns));
            if ($safeColumns === []) { return null; }
            $query->select(array_map(static fn (string $column): string => $table.'.'.$column, $safeColumns));
        }
        foreach (AssistantExtendedDomainRegistry::values('rowPredicates')[$type] ?? [] as $column => $value) {
            if (! in_array($column, $columns, true) || (! is_scalar($value) && $value !== null)) { return null; }
            $value === null ? $query->whereNull($table.'.'.$column) : $query->where($table.'.'.$column, $value);
        }
        foreach (AssistantExtendedDomainRegistry::values('rowColumnMatches')[$type] ?? [] as $left => $right) {
            if (! in_array($left, $columns, true) || ! in_array($right, $columns, true)) { return null; }
            $query->whereColumn($table.'.'.$left, $table.'.'.$right);
        }
        $actorColumn = AssistantExtendedDomainRegistry::values('actorColumns')[$type] ?? null;
        if ($actorColumn !== null) {
            if (! is_string($actorColumn) || ! in_array($actorColumn, $columns, true)) { return null; }
            $query->where($table.'.'.$actorColumn, $user->id);
        }
        if (! AssistantExtendedDomainRegistry::applyActorScopes($type, $query, $user, $organizationId, $this->currentAuthorization(), $this)) { return null; }
        if ($type === 'site_request') {
            (new \App\BusinessModules\Features\SiteRequests\Models\SiteRequest)->scopeVisibleToActor($query, (int) $user->id);
        }
        if (isset(self::PARENTS[$type])) {
            [$relation, $parentType] = self::PARENTS[$type];
            $parent = $this->entityQuery($user, $organizationId, $parentType);
            if ($parent === null) {
                return null;
            }
            $parentTable = $parent->getModel()->getTable();
            if (in_array('organization_id', $columns, true)) { $query->where($table.'.organization_id', $organizationId); }
            return $query->whereHas($relation, static fn (Builder $related): Builder => $related->whereIn($parentTable.'.id', $parent->select($parentTable.'.id')));
        }
        if ($type === 'estimate_library_item') {
            return $query->whereHas('library', static fn (Builder $library): Builder => $library->where('organization_id', $organizationId)->orWhere('access_level', 'public'));
        }
        if ($type === 'normative_rate') {
            $estimates = $this->entityQuery($user, $organizationId, 'estimate');
            return $estimates === null ? null : $query->whereHas('estimateItems.estimate', static fn (Builder $related): Builder => $related->whereIn('estimates.id', $estimates->select('estimates.id')));
        }
        $organizationColumns = AssistantExtendedDomainRegistry::values('organizationColumns');
        $organizationColumn = array_key_exists($type, $organizationColumns) ? $organizationColumns[$type] : 'organization_id';
        $publicCatalog = AssistantExtendedDomainRegistry::values('publicCatalogEntities')[$type] ?? null;
        $globalCatalog = AssistantExtendedDomainRegistry::values('globalCatalogEntities')[$type] ?? false;
        $customOrganizationScope = (AssistantExtendedDomainRegistry::values('customOrganizationScopes')[$type] ?? false) === true;
        if ($customOrganizationScope && ! AssistantExtendedDomainRegistry::applyCustomOrganizationScope($type, $query, $user, $organizationId, $this->currentAuthorization(), $this)) { return null; }
        $extendedParents = (AssistantExtendedDomainRegistry::values('parentColumns') + self::SECURITY_PARENT_COLUMNS)[$type] ?? [];
        $hasRequiredParent = array_filter($extendedParents, static fn (array $parent): bool => ! $parent['nullable']) !== [];
        if (! in_array($organizationColumn, $columns, true) && ! $hasRequiredParent && ! $globalCatalog && ! $customOrganizationScope) { return null; }
        if (is_array($publicCatalog)) {
            $query->where(static fn (Builder $catalog): Builder => $catalog->where($table.'.'.$organizationColumn, $organizationId)
                ->orWhere($table.'.'.$publicCatalog['approval_column'], $publicCatalog['approved_value']));
        } elseif (AssistantExtendedDomainRegistry::values('organizationNullableCatalogs')[$type] ?? false) {
            $query->where(static fn (Builder $catalog): Builder => $catalog->where($table.'.'.$organizationColumn, $organizationId)->orWhereNull($table.'.'.$organizationColumn));
        } elseif ($type === 'estimate_template') {
            $query->where(static fn (Builder $template): Builder => $template->where('organization_id', $organizationId)->orWhere('is_public', true));
        } elseif (! $customOrganizationScope && in_array($organizationColumn, $columns, true)) {
            $query->where($table.'.'.$organizationColumn, $organizationId);
        }
        if ($type === 'project_pulse_report') {
            $this->applyReferenceScope($query, $table.'.source_refs', $table.'.required_domains', $user, $organizationId);
        }
        if ($type === 'design_artifact') {
            $documents = $this->currentAuthorization()->canCurrent($user, 'design-management.documents.view', ['organization_id' => $organizationId]);
            $models = $this->currentAuthorization()->canCurrent($user, 'design-management.models.view', ['organization_id' => $organizationId]);
            $query->where(function (Builder $artifacts) use ($documents, $models, $table): void {
                $artifacts->whereRaw('1 = 0');
                if ($models) { $artifacts->orWhere($table.'.artifact_type', 'model'); }
                if ($documents) { $artifacts->orWhere($table.'.artifact_type', '!=', 'model'); }
            });
        }
        if (in_array($type, ['design_artifact', 'design_artifact_version', 'design_review_comment'], true)) {
            $parents = $type === 'design_artifact_version' ? ['artifact_id' => 'design_artifact'] : ['package_id' => 'design_package'];
            if ($type === 'design_review_comment') { $parents += ['artifact_id' => 'design_artifact', 'version_id' => 'design_artifact_version']; }
            foreach ($parents as $column => $parentType) {
                $parent = $this->entityQuery($user, $organizationId, $parentType);
                if ($parent !== null) {
                    $parentTable = $parent->getModel()->getTable();
                    $parent->whereRaw($parentTable.'.project_id IS NOT DISTINCT FROM '.$table.'.project_id');
                }
                $query->where(function (Builder $linked) use ($parent, $column, $table, $type): void {
                    $linked->whereRaw('1 = 0');
                    if ($type === 'design_review_comment' && $column !== 'package_id') { $linked->orWhereNull($table.'.'.$column); }
                    if ($parent !== null) {
                        $parentTable = $parent->getModel()->getTable();
                        $linked->orWhereIn($table.'.'.$column, $parent->select($parentTable.'.id'));
                    }
                });
            }
        }
        if (in_array('deleted_at', $columns, true)) {
            $query->whereNull($table.'.deleted_at');
        }
        if ($type === 'contract') {
            $query->whereDoesntHave('projects', fn (Builder $projects): Builder => $projects->whereNotIn('projects.id', $this->accessibleProjects($user, $organizationId)->select('projects.id')));
        }
        if (in_array('project_id', $columns, true)) {
            $query->where(function (Builder $projects) use ($user, $organizationId, $table): void {
                $projects->whereNull($table.'.project_id')->orWhereIn($table.'.project_id', $this->accessibleProjects($user, $organizationId)->select('projects.id'));
            });
        }
        foreach (['current_project_id', 'target_project_id', 'source_project_id'] as $projectColumn) {
            if (in_array($projectColumn, $columns, true)) {
                $query->where(function (Builder $projects) use ($user, $organizationId, $table, $projectColumn): void {
                    $projects->whereNull($table.'.'.$projectColumn)->orWhereIn($table.'.'.$projectColumn, $this->accessibleProjects($user, $organizationId)->select('projects.id'));
                });
            }
        }
        foreach (['contract_id' => 'contract', 'estimate_id' => 'estimate', 'deal_id' => 'crm_deal', 'site_request_id' => 'site_request', 'purchase_request_id' => 'purchase_request', 'purchase_order_id' => 'purchase_order'] as $parentColumn => $parentType) {
            if ($parentType === $type || ! in_array($parentColumn, $columns, true)) {
                continue;
            }
            if (($extendedParents[$parentColumn]['type'] ?? null) === $parentType) { continue; }
            $parent = $type === 'purchase_order' && $parentColumn === 'purchase_request_id'
                ? \App\BusinessModules\Features\Procurement\Models\PurchaseRequest::query()
                    ->where('purchase_requests.organization_id', $organizationId)
                    ->where(function (Builder $requests) use ($user, $organizationId): void {
                        $requests->whereNull('purchase_requests.site_request_id')
                            ->orWhereHas('siteRequest', function (Builder $sites) use ($user, $organizationId): void {
                                $sites->where('site_requests.organization_id', $organizationId)
                                    ->whereIn('site_requests.project_id', $this->accessibleProjects($user, $organizationId)->select('projects.id'));
                            });
                    })
                : $this->entityQuery($user, $organizationId, $parentType);
            $query->where(function (Builder $linked) use ($parent, $parentColumn, $table): void {
                $linked->whereNull($table.'.'.$parentColumn);
                if ($parent !== null) {
                    $parentTable = $parent->getModel()->getTable();
                    $linked->orWhereIn($table.'.'.$parentColumn, $parent->select($parentTable.'.id'));
                }
            });
        }
        foreach ($extendedParents as $column => $definition) {
            $referenceOnly = ($definition['reference_only'] ?? false) === true;
            if ($referenceOnly && $definition['type'] !== $type) { return null; }
            if ($skipBusinessReferences && $referenceOnly) { continue; }
            $parent = $referenceOnly ? $this->buildEntityQuery($user, $organizationId, $type, true)
                : $this->entityQuery($user, $organizationId, $definition['type']);
            if ($parent !== null) {
                $parentTable = $parent->getModel()->getTable();
                if (($definition['match_project'] ?? false) || $type === 'video_camera_event') {
                    foreach ($definition['matches'] ?? [] as $parentColumn => $childColumn) {
                        $parent->whereColumn($parentTable.'.'.$parentColumn, $table.'.'.$childColumn);
                    }
                }
            }
            if ($parent !== null && (($definition['match_project'] ?? false) || $type === 'video_camera_event')) {
                $parentTable = $parent->getModel()->getTable();
                $parent->whereRaw($parentTable.'.project_id IS NOT DISTINCT FROM '.$table.'.project_id');
            }
            $query->where(function (Builder $linked) use ($parent, $column, $table, $definition, $type): void {
                $linked->whereRaw('1 = 0');
                if ($definition['nullable']) { $linked->orWhereNull($table.'.'.$column); }
                if ($parent !== null) {
                    $parentTable = $parent->getModel()->getTable();
                    $parentKey = $definition['key'] ?? 'id';
                    if (($definition['matches'] ?? []) !== [] && ! ($definition['match_project'] ?? false) && $type !== 'video_camera_event') {
                        $grammar = $linked->getQuery()->getGrammar();
                        $childColumns = [$table.'.'.$column];
                        $parentColumns = [$parentTable.'.'.$parentKey];
                        foreach ($definition['matches'] as $parentColumn => $childColumn) {
                            $childColumns[] = $table.'.'.$childColumn;
                            $parentColumns[] = $parentTable.'.'.$parentColumn;
                        }
                        $tuple = '('.implode(', ', array_map($grammar->wrap(...), $childColumns)).')';
                        $linked->orWhereIn(DB::raw($tuple), $parent->select($parentColumns));
                    } else {
                        $linked->orWhereIn($table.'.'.$column, $parent->select($parentTable.'.'.$parentKey));
                    }
                }
            });
        }
        if ($type === 'payment_document') {
            $query->where(function (Builder $invoice) use ($user, $organizationId, $table): void {
                $invoice->where(function (Builder $unlinked) use ($table): void { $unlinked->whereNull($table.'.invoiceable_type')->whereNull($table.'.invoiceable_id'); });
                foreach (['contract', 'estimate', 'performance_act', 'completed_work'] as $parentType) {
                    $parent = $this->entityQuery($user, $organizationId, $parentType);
                    if ($parent !== null) {
                        $parentModel = $parent->getModel();
                        $invoice->orWhere(function (Builder $linked) use ($parent, $parentModel, $table): void {
                            $linked->whereIn($table.'.invoiceable_type', [get_class($parentModel), $parentModel->getMorphClass()])
                                ->whereIn($table.'.invoiceable_id', $parent->select($parentModel->getTable().'.id'));
                        });
                    }
                }
            });
        }
        return $query;
    }

    public function applyReferenceScope(Builder $query, string $refsColumn, string $domainsColumn, User $user, int $organizationId): Builder
    {
        if ($this->aclCompiler === null) {
            return $this->compileAcl($user, $organizationId, fn (): Builder => $this->applyReferenceScope($query, $refsColumn, $domainsColumn, $user, $organizationId)) ?? $query->whereRaw('1 = 0');
        }
        $conditions = [];
        $bindings = [];
        $this->currentCheckpoint?->__invoke();
        $candidateReferences = $query->toBase()->cloneWithout(['columns', 'orders', 'limit', 'offset'])->selectRaw($refsColumn.' AS candidate_refs');
        $referenceTypeQuery = \Illuminate\Support\Facades\DB::query()->fromSub($candidateReferences, 'candidate_reports')
            ->crossJoin(\Illuminate\Support\Facades\DB::raw("LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(candidate_reports.candidate_refs) = 'array' THEN candidate_reports.candidate_refs ELSE '[]'::jsonb END) AS ref"))
            ->distinct()->selectRaw("ref->>'entity_type' AS entity_type");
        $referenceTypes = $this->finishAclDiscovery($query, $referenceTypeQuery)->pluck('entity_type')->all();
        foreach ($this->entities() as $type => $definition) {
            if (! in_array($type, $referenceTypes, true)) { continue; }
            if ($type === 'project_pulse_report' || \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderSourceSchema::revision($type) !== null) {
                continue;
            }
            $entities = $this->entityContentQuery($user, $organizationId, $type);
            if ($entities === null) {
                continue;
            }
            $entities->select([])->selectRaw('CAST('.$entities->getModel()->getQualifiedKeyName().' AS TEXT)');
            $conditions[] = "(ref->>'entity_type' = ? AND ref->>'entity_id' IN (".$entities->toSql().'))';
            $bindings[] = $type;
            array_push($bindings, ...$entities->getBindings());
        }
        $query->whereNotNull($refsColumn)->whereRaw('jsonb_typeof('.$refsColumn.") = 'array'")->whereRaw($refsColumn." <> '[]'::jsonb");
        if ($conditions === []) {
            return $query->whereRaw('1 = 0');
        }
        $shape = "jsonb_typeof(ref) = 'object' AND CASE WHEN jsonb_typeof(ref) = 'object' THEN ref - 'entity_type' - 'entity_id' ELSE 'null'::jsonb END = '{}'::jsonb"
            ." AND jsonb_typeof(ref->'entity_type') = 'string' AND jsonb_typeof(ref->'entity_id') IN ('string', 'number')"
            ." AND (ref->>'entity_id' ~ '^[1-9][0-9]{0,19}$' OR ref->>'entity_id' ~* '^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$' OR ref->>'entity_id' ~ '^[0-9A-HJKMNP-TV-Z]{26}$')";
        $query->whereRaw('NOT EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof('.$refsColumn.") = 'array' THEN ".$refsColumn." ELSE '[]'::jsonb END) ref WHERE NOT COALESCE((".$shape.' AND ('.implode(' OR ', $conditions).')), FALSE))', $bindings);
        $domains = array_keys(array_filter(AssistantExtendedDomainRegistry::values('domainGates') + self::DOMAINS,
            fn (array $definition, string $domain): bool => $this->canReadDomain($user, $organizationId, $domain), ARRAY_FILTER_USE_BOTH));
        if ($domains === []) {
            return $query->whereRaw('1 = 0');
        }
        return $query->whereNotNull($domainsColumn)->whereRaw('jsonb_typeof('.$domainsColumn.") = 'array'")->whereRaw($domainsColumn." <> '[]'::jsonb")
            ->whereRaw('NOT EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof('.$domainsColumn.") = 'array' THEN ".$domainsColumn." ELSE '[]'::jsonb END) domain WHERE jsonb_typeof(domain) <> 'string' OR domain #>> '{}' NOT IN (".implode(',', array_fill(0, count($domains), '?')).'))', $domains);
    }

    private function canReadIndexedType(User $user, int $organizationId, string $type): bool
    {
        if ($this->aclCompiler !== null && ! $this->aclCompiler->accepts((int) $user->id, $organizationId)) { return false; }
        return $this->rememberCurrent($user, $organizationId, 'indexed:'.$type, fn (): bool => $this->checkIndexedType($user, $organizationId, $type));
    }

    private function checkIndexedType(User $user, int $organizationId, string $type): bool
    {
        foreach (AssistantExtendedDomainRegistry::values('sourcePermissions')[$type] ?? [] as $permission) {
            if (! $this->currentAuthorization()->canCurrent($user, $permission, ['organization_id' => $organizationId])) { return false; }
        }
        if ($type === 'payment' && ! $this->currentAuthorization()->canCurrent($user, 'payments.invoice.view', ['organization_id' => $organizationId])
            && ! $this->currentAuthorization()->canCurrent($user, 'payments.invoice.view_all', ['organization_id' => $organizationId])) { return false; }
        $permission = match ($type) {
            'estimate', 'estimate_generation_learning', 'estimate_reference' => 'budget-estimates.finance.view',
            'performance_act' => 'contracts.performance_acts.view', 'work_completion' => 'contracts.completed_works.view',
            default => null,
        };
        if ($permission !== null && ! $this->currentAuthorization()->canCurrent($user, $permission, ['organization_id' => $organizationId])) {
            return false;
        }
        return ! in_array($type, ['project', 'contract', 'project_pulse', 'performance_act', 'work_completion', 'warehouse', 'procurement', 'change_management', 'machinery', 'production_labor'], true) || $this->canReadDomain($user, $organizationId, 'finance');
    }

    public function entityTypeForModel(Model|string $model): ?string
    {
        $class = $model instanceof Model ? $model::class : (\Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($model) ?? $model);
        foreach ($this->entities() as $type => $definition) {
            if ($definition[1] === $class) {
                return $type;
            }
        }
        return null;
    }

    public function domainForEntity(string $type): ?string
    {
        return $this->entities()[$type][2] ?? null;
    }

    public function entityTypeForTable(string $table): ?string
    {
        foreach ($this->entities() as $type => $definition) {
            if ((new $definition[1])->getTable() === $table) {
                return $type;
            }
        }
        return null;
    }

    private function entities(): array
    {
        return self::entityDefinitions();
    }

    public static function entityDefinitions(): array
    {
        return AssistantExtendedDomainRegistry::values('entityDefinitions') + [
            'project' => ['project', Project::class, 'projects'],
            'contract' => ['contract', \App\Models\Contract::class, 'contracts'],
            'estimate' => ['estimate', \App\Models\Estimate::class, 'estimates'],
            'estimate_section' => ['estimate', \App\Models\EstimateSection::class, 'estimates'],
            'estimate_item' => ['estimate', \App\Models\EstimateItem::class, 'estimates'],
            'estimate_item_resource' => ['estimate', \App\Models\EstimateItemResource::class, 'estimates'],
            'estimate_template' => ['estimate_reference', \App\Models\EstimateTemplate::class, 'estimates'],
            'estimate_library_item' => ['estimate_reference', \App\Models\EstimateLibraryItem::class, 'estimates'],
            'normative_rate' => ['estimate_reference', \App\Models\NormativeRate::class, 'estimates'],
            'estimate_catalog_item' => ['estimate_reference', \App\Models\EstimatePositionCatalog::class, 'estimates'],
            'estimate_generation_learning_example' => ['estimate_generation_learning', \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample::class, 'estimates'],
            'payment_document' => ['payment', \App\BusinessModules\Core\Payments\Models\PaymentDocument::class, 'finance'],
            'performance_act' => ['performance_act', \App\Models\ContractPerformanceAct::class, 'contracts'],
            'performance_act_line' => ['performance_act', \App\Models\PerformanceActLine::class, 'contracts'],
            'completed_work' => ['work_completion', \App\Models\CompletedWork::class, 'projects'],
            'design_package' => ['design', \App\BusinessModules\Features\DesignManagement\Models\DesignPackage::class, 'design'],
            'design_artifact' => ['design', \App\BusinessModules\Features\DesignManagement\Models\DesignArtifact::class, 'design'],
            'design_artifact_version' => ['design', \App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion::class, 'design'],
            'design_review_comment' => ['design', \App\BusinessModules\Features\DesignManagement\Models\DesignReviewComment::class, 'design'],
            'design_model_set' => ['design', \App\BusinessModules\Features\DesignManagement\Models\DesignModelSet::class, 'design'],
            'schedule' => ['schedule', \App\Models\ProjectSchedule::class, 'schedule'],
            'schedule_task' => ['schedule', \App\Models\ScheduleTask::class, 'schedule'],
            'construction_journal' => ['construction_journal', \App\Models\ConstructionJournal::class, 'projects'],
            'construction_journal_entry' => ['construction_journal', \App\Models\ConstructionJournalEntry::class, 'projects'],
            'project_pulse_report' => ['project_pulse', \App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport::class, 'reports'],
            'material' => ['warehouse', \App\Models\Material::class, 'materials'],
            'change_approval' => ['change_management', \App\BusinessModules\Features\ChangeManagement\Models\ChangeApproval::class, 'change_management'],
            'change_claim' => ['change_management', \App\BusinessModules\Features\ChangeManagement\Models\ChangeClaim::class, 'change_management'],
            'change_impact' => ['change_management', \App\BusinessModules\Features\ChangeManagement\Models\ChangeImpact::class, 'change_management'],
            'change_management_rfi' => ['change_management', \App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfi::class, 'change_management'],
            'change_request' => ['change_management', \App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest::class, 'change_management'],
            'variation_order' => ['change_management', \App\BusinessModules\Features\ChangeManagement\Models\VariationOrder::class, 'change_management'],
            'acceptance_checklist' => ['handover_acceptance', \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceChecklist::class, 'handover_acceptance'],
            'acceptance_checklist_item' => ['handover_acceptance', \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceChecklistItem::class, 'handover_acceptance'],
            'acceptance_finding' => ['handover_acceptance', \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceFinding::class, 'handover_acceptance'],
            'acceptance_scope' => ['handover_acceptance', \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope::class, 'handover_acceptance'],
            'acceptance_session' => ['handover_acceptance', \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceSession::class, 'handover_acceptance'],
            'acceptance_signoff' => ['handover_acceptance', \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceSignoff::class, 'handover_acceptance'],
            'handover_package' => ['handover_acceptance', \App\BusinessModules\Features\HandoverAcceptance\Models\HandoverPackage::class, 'handover_acceptance'],
            'handover_package_document' => ['handover_acceptance', \App\BusinessModules\Features\HandoverAcceptance\Models\HandoverPackageDocument::class, 'handover_acceptance'],
            'project_location' => ['handover_acceptance', \App\BusinessModules\Features\HandoverAcceptance\Models\ProjectLocation::class, 'handover_acceptance'],
            'warehouse_asset' => ['warehouse', \App\BusinessModules\Features\BasicWarehouse\Models\Asset::class, 'warehouse'],
            'asset_reservation' => ['warehouse', \App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation::class, 'warehouse'],
            'inventory_act' => ['warehouse', \App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct::class, 'warehouse'],
            'project_material_delivery' => ['warehouse', \App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery::class, 'warehouse'],
            'warehouse_balance' => ['warehouse', \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance::class, 'warehouse'],
            'warehouse_movement' => ['warehouse', \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::class, 'warehouse'],
            'warehouse_project_allocation' => ['warehouse', \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation::class, 'warehouse'],
            'warehouse_storage_cell' => ['warehouse', \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseStorageCell::class, 'warehouse'],
            'warehouse_task' => ['warehouse', \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseTask::class, 'warehouse'],
            'site_request' => ['site_request', \App\BusinessModules\Features\SiteRequests\Models\SiteRequest::class, 'site_requests'],
            'machinery_asset' => ['machinery', \App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset::class, 'machinery'],
            'machinery_assignment' => ['machinery', \App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment::class, 'machinery'],
            'machinery_downtime' => ['machinery', \App\BusinessModules\Features\MachineryOperations\Models\MachineryDowntime::class, 'machinery'],
            'machinery_fuel_issue' => ['machinery', \App\BusinessModules\Features\MachineryOperations\Models\MachineryFuelIssue::class, 'machinery'],
            'machinery_maintenance_order' => ['machinery', \App\BusinessModules\Features\MachineryOperations\Models\MachineryMaintenanceOrder::class, 'machinery'],
            'machinery_production_record' => ['machinery', \App\BusinessModules\Features\MachineryOperations\Models\MachineryProductionRecord::class, 'machinery'],
            'machinery_shift_report' => ['machinery', \App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport::class, 'machinery'],
            'procurement_approval' => ['procurement', \App\BusinessModules\Features\Procurement\Models\ProcurementApproval::class, 'procurement'],
            'procurement_audit_event' => ['procurement', \App\BusinessModules\Features\Procurement\Models\ProcurementAuditEvent::class, 'procurement'],
            'purchase_order' => ['procurement', \App\BusinessModules\Features\Procurement\Models\PurchaseOrder::class, 'procurement_business'],
            'purchase_request' => ['procurement', \App\BusinessModules\Features\Procurement\Models\PurchaseRequest::class, 'procurement'],
            'purchase_receipt' => ['procurement', \App\BusinessModules\Features\Procurement\Models\PurchaseReceipt::class, 'procurement'],
            'supplier_proposal' => ['procurement', \App\BusinessModules\Features\Procurement\Models\SupplierProposal::class, 'procurement'],
            'supplier_proposal_decision' => ['procurement', \App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision::class, 'procurement'],
            'supplier_request' => ['procurement', \App\BusinessModules\Features\Procurement\Models\SupplierRequest::class, 'procurement'],
            'safety_briefing' => ['safety', \App\BusinessModules\Features\SafetyManagement\Models\SafetyBriefing::class, 'safety'],
            'safety_corrective_action' => ['safety', \App\BusinessModules\Features\SafetyManagement\Models\SafetyCorrectiveAction::class, 'safety'],
            'safety_inspection' => ['safety', \App\BusinessModules\Features\SafetyManagement\Models\SafetyInspection::class, 'safety'],
            'safety_inspection_finding' => ['safety', \App\BusinessModules\Features\SafetyManagement\Models\SafetyInspectionFinding::class, 'safety'],
            'safety_incident' => ['safety', \App\BusinessModules\Features\SafetyManagement\Models\SafetyIncident::class, 'safety'],
            'safety_violation' => ['safety', \App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation::class, 'safety'],
            'safety_work_permit' => ['safety', \App\BusinessModules\Features\SafetyManagement\Models\SafetyWorkPermit::class, 'safety'],
            'executive_document' => ['quality_executive_docs', \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument::class, 'documents'],
            'executive_document_set' => ['quality_executive_docs', \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet::class, 'documents'],
            'quality_defect' => ['quality_executive_docs', \App\BusinessModules\Features\QualityControl\Models\QualityDefect::class, 'quality'],
            'production_labor_output_entry' => ['production_labor', \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborOutputEntry::class, 'production_labor'],
            'production_labor_payroll_accrual' => ['production_labor', \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborPayrollAccrual::class, 'production_labor'],
            'production_labor_timesheet' => ['production_labor', \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborTimesheet::class, 'production_labor'],
            'production_labor_timesheet_entry' => ['production_labor', \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborTimesheetEntry::class, 'production_labor'],
            'production_labor_work_order' => ['production_labor', \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrder::class, 'production_labor'],
            'production_labor_work_order_line' => ['production_labor', \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrderLine::class, 'production_labor'],
            'user' => ['people', User::class, 'people'],
            'warehouse' => ['warehouse', \App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse::class, 'warehouse'],
            'crm_deal' => ['crm', \App\BusinessModules\Features\Crm\Models\CrmDeal::class, 'crm'],
            'crm_lead' => ['crm', \App\BusinessModules\Features\Crm\Models\CrmLead::class, 'crm'],
            'crm_company' => ['crm', \App\BusinessModules\Features\Crm\Models\CrmCompany::class, 'crm'],
            'crm_contact' => ['crm', \App\BusinessModules\Features\Crm\Models\CrmContact::class, 'crm'],
            'crm_activity' => ['crm', \App\BusinessModules\Features\Crm\Models\CrmActivity::class, 'crm'],
            'customer_issue' => ['crm', \App\Models\CustomerIssue::class, 'crm'],
            'knowledge_article' => ['knowledge', \App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeArticle::class, 'knowledge'],
            'measurement_unit' => ['measurement_units', \App\Models\MeasurementUnit::class, 'measurement_units'],
            'contractor' => ['contract', \App\Models\Contractor::class, 'contractors'],
            'time_entry' => ['time_tracking', \App\Models\TimeEntry::class, 'time_tracking'],
            'commercial_proposal' => ['commercial_processes', \App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal::class, 'commercial_processes'],
        ];
    }

    private function currentNativeFile(User $user, int $organizationId, File $file): bool
    {
        $type = $this->entityTypeForModel((string) $file->fileable_type);
        try {
            if ($type === 'design_artifact_version') {
                (new \App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDesignFileAdapter($this))->assertMapping($file);
            } elseif ($type !== null && \App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeFileRegistry::supports($type)) {
                \App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeFileRegistry::adapter($type)?->assertReadable($user, $organizationId, $file);
            }
            return true;
        } catch (\RuntimeException $exception) {
            $this->rethrowReadFailure($exception);
            return false;
        }
    }

    private function rethrowReadFailure(\RuntimeException $exception): void
    {
        if ($exception instanceof \Illuminate\Database\QueryException
            || $exception instanceof \App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded
            || $exception instanceof \App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled
            || $exception instanceof \App\BusinessModules\Features\AIAssistant\Services\Rag\RagStatusBudgetExceeded) {
            throw $exception;
        }
    }
}
