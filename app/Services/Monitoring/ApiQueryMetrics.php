<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\BusinessModules\Features\AIAssistant\Services\AssistantEntityAccessCatalog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Redis\Events\CommandExecuted;

final class ApiQueryMetrics
{
    public const REQUEST_ATTRIBUTE = 'most.api_query_metrics';

    private const MAX_SOURCE_GROUPS = 64;

    private const MAX_VARIANTS = 16;

    private const MAX_ENTITY_BUILD_GROUPS = 256;

    private const MAX_REPORTED_ENTITY_BUILDS = 16;

    private const MAX_SOURCE_BATCHES = 16;

    private const MAX_BATCH_IDENTITIES = 32;

    private int $count = 0;

    private float $total = 0;

    private float $maximum = 0;

    private array $groups = [];

    private array $sources = [];

    private int $sourcesDropped = 0;

    private float $sourceCaptureMilliseconds = 0;

    private array $processingPhases = [];

    private array $entityBuilds = [];

    private array $sourceBatches = [];

    private int $sourceBatchOrdinal = 0;

    private int $sourceBatchesDropped = 0;

    private array $accessReads = [];

    private ?\WeakMap $accessReadObjects = null;

    private array $accessReadOrganizations = [];

    private array $roleCatalogRedisGet = ['observed' => false, 'count' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0];

    private ?array $assistantSnapshot = null;

    private ?array $assistantSnapshotEpoch = null;

    public function __construct(private readonly bool $captureSources = false) {}

    public static function recordQuery(Request $request, QueryExecuted $query): void
    {
        $metrics = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if (! $metrics instanceof self || ! is_finite((float) $query->time) || $query->time < 0) {
            return;
        }
        self::record($request, (float) $query->time);
        $sql = strtolower($query->sql);
        $group = match (true) {
            str_starts_with($sql, 'set '), str_contains($sql, "set_config('statement_timeout'") => 'settings',
            str_contains($sql, 'pg_catalog'), str_contains($sql, 'information_schema'), str_contains($sql, 'from pg_attribute ') => 'schema',
            str_contains($sql, 'stored_count'), str_contains($sql, 'expected_count') => 'rag_counts',
            preg_match('/\b(?:from|join)\s+"?role_conditions\b/', $sql) === 1 => 'role_conditions',
            preg_match('/\b(?:from|join)\s+"?authorization_contexts\b/', $sql) === 1 => 'contexts',
            preg_match('/\b(?:from|join)\s+"?ai_rag_sources\b/', $sql) === 1 => 'rag_sources',
            preg_match('/\b(?:from|join)\s+"?(?:files|ai_assistant_documents)\b/', $sql) === 1 => 'documents',
            default => 'other',
        };
        $metrics->groups[$group]['count'] = ($metrics->groups[$group]['count'] ?? 0) + 1;
        $metrics->groups[$group]['total_ms'] = ($metrics->groups[$group]['total_ms'] ?? 0) + (float) $query->time;
        if ($metrics->captureSources) {
            $sourceStartedAt = hrtime(true);
            try {
                $metrics->recordSource($query, $group);
            } catch (\Throwable) {
                $metrics->sourcesDropped++;
            } finally {
                $metrics->sourceCaptureMilliseconds += (hrtime(true) - $sourceStartedAt) / 1_000_000;
            }
        }
    }

    public static function record(Request $request, float $milliseconds): void
    {
        $metrics = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if (! $metrics instanceof self || ! is_finite($milliseconds) || $milliseconds < 0) {
            return;
        }

        $metrics->count++;
        $metrics->total += $milliseconds;
        $metrics->maximum = max($metrics->maximum, $milliseconds);
    }

    public static function measureProcessingPhase(string $phase, callable $operation, ?string $entityType = null): mixed
    {
        $request = app()->bound('request') ? app('request') : null;
        $checkpoint = $request instanceof Request ? self::processingCheckpoint($request) : null;
        if ($checkpoint === null) { return $operation(); }

        try {
            return $operation();
        } finally {
            self::recordProcessingPhase($request, $phase, $checkpoint['started_at'], $checkpoint, $entityType);
        }
    }

    public static function measureRagSourceBatch(array $identities, bool $expectedProjection, callable $operation): mixed
    {
        $request = app()->bound('request') ? app('request') : null;
        $checkpoint = $request instanceof Request ? self::processingCheckpoint($request) : null;
        if ($checkpoint === null) { return $operation(); }

        $ordinal = ++$checkpoint['metrics']->sourceBatchOrdinal;
        $completed = false;
        try {
            $result = $operation();
            $completed = true;

            return $result;
        } finally {
            self::recordRagSourceBatch($request, $checkpoint, $ordinal, $identities, $expectedProjection, $completed);
        }
    }

    private static function recordRagSourceBatch(Request $request, array $checkpoint, int $ordinal, array $identities, bool $expectedProjection, bool $completed): void
    {
        try {
            $metrics = $request->attributes->get(self::REQUEST_ATTRIBUTE);
            if (! $metrics instanceof self || ! $metrics->captureSources || $checkpoint['metrics'] !== $metrics) { return; }
            if ($ordinal > self::MAX_SOURCE_BATCHES) {
                $metrics->sourceBatchesDropped++;

                return;
            }
            $milliseconds = (hrtime(true) - $checkpoint['started_at']) / 1_000_000;
            $sqlTotal = $metrics->total - $checkpoint['sql_total_ms'];
            if (! is_finite($milliseconds) || $milliseconds < 0 || ! is_finite($sqlTotal) || $sqlTotal < 0
                || $metrics->count < $checkpoint['sql_count']) { return; }
            $cpu = self::processCpuMilliseconds();
            try {
                [$pairs, $complete] = self::sourceBatchIdentities($identities);
            } catch (\Throwable) {
                [$pairs, $complete] = [[], false];
            }
            $measurement = ['ordinal' => $ordinal, 'mode' => $expectedProjection ? 'expected' : 'stored',
                'candidate_identities' => $pairs, 'candidate_identities_complete' => $complete, 'completed' => $completed,
                'duration_ms' => round($milliseconds, 2), 'sql_count' => $metrics->count - $checkpoint['sql_count'],
                'sql_total_ms' => round($sqlTotal, 2)];
            $startedCpu = $checkpoint['process_cpu_ms'];
            if ($cpu !== null && is_float($startedCpu) && is_finite($startedCpu) && $startedCpu >= 0 && $cpu >= $startedCpu) {
                $measurement['process_cpu_ms'] = round($cpu - $startedCpu, 2);
            }
            $metrics->sourceBatches[$ordinal] = $measurement;
        } catch (\Throwable) {
        }
    }

    private static function sourceBatchIdentities(array $identities): array
    {
        $definitions = AssistantEntityAccessCatalog::entityDefinitions();
        $pairs = [];
        $complete = true;
        $entitiesExamined = 0;
        $pairsExamined = 0;
        foreach ($identities as $entityType => $sources) {
            if (++$entitiesExamined > 128) { $complete = false; break; }
            if (! is_string($entityType) || preg_match('/^[a-z][a-z0-9_]{0,127}$/D', $entityType) !== 1
                || ! is_array($sources)) { $complete = false; continue; }
            $canonicalSource = $definitions[$entityType][0] ?? null;
            $allowedSources = $entityType === 'assistant_document' ? ['file_document']
                : (is_string($canonicalSource) ? AssistantEntityAccessCatalog::indexedSourceTypes($entityType, $canonicalSource) : []);
            foreach ($sources as $sourceType => $_) {
                if (++$pairsExamined > 128) { $complete = false; break 2; }
                if (! is_string($sourceType) || preg_match('/^[a-z][a-z0-9_]{0,127}$/D', $sourceType) !== 1
                    || ! in_array($sourceType, $allowedSources, true)) { $complete = false; continue; }
                if (count($pairs) >= self::MAX_BATCH_IDENTITIES) { $complete = false; break 2; }
                $pairs[] = ['entity_type' => $entityType, 'source_type' => $sourceType];
            }
        }

        return [$pairs, $complete];
    }

    public static function recordAccessRead(string $event, ?object $frame = null, ?object $store = null, ?int $organizationId = null): void
    {
        try {
            $request = app()->bound('request') ? app('request') : null;
            $metrics = $request instanceof Request ? $request->attributes->get(self::REQUEST_ATTRIBUTE) : null;
            if (! $metrics instanceof self || ! $metrics->captureSources || ! in_array($event, [
                'scope_memoized', 'scope_plain', 'current_reuse', 'current_plain',
                'policy_enter', 'policy_reuse', 'policy_restore', 'policy_module_lookup', 'policy_module_load',
                'policy_outer', 'policy_outer_fresh', 'policy_nested_fresh_same', 'policy_nested_fresh_changed',
                'policy_nested_actor_changed', 'policy_nested_identity_changed', 'policy_nested_org_changed',
                'module_lookup_memoized', 'module_lookup_plain', 'module_load_full', 'module_load_matching',
                'entitlement_full', 'entitlement_matching',
            ], true)) { return; }

            $metrics->accessReads[$event] ??= ['count' => 0, 'frames' => 0, 'stores' => 0, 'organizations' => 0,
                'objects_dropped' => 0, 'organizations_dropped' => 0, 'callers' => []];
            $measurement = &$metrics->accessReads[$event];
            $measurement['count']++;
            $metrics->accessReadObjects ??= new \WeakMap;
            foreach (['frames' => $frame, 'stores' => $store] as $kind => $object) {
                if ($object === null) { continue; }
                if (! isset($metrics->accessReadObjects[$object]) && count($metrics->accessReadObjects) >= 128) {
                    $measurement['objects_dropped']++;
                    continue;
                }
                $seen = $metrics->accessReadObjects[$object] ?? [];
                $key = $event.':'.$kind;
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $metrics->accessReadObjects[$object] = $seen;
                    $measurement[$kind]++;
                }
            }
            if ($organizationId !== null && $organizationId > 0 && ! isset($metrics->accessReadOrganizations[$event][$organizationId])) {
                if (count($metrics->accessReadOrganizations[$event] ?? []) < 64) {
                    $metrics->accessReadOrganizations[$event][$organizationId] = true;
                    $measurement['organizations']++;
                } else {
                    $measurement['organizations_dropped']++;
                }
            }
            $caller = 'other';
            $callers = [
                \App\Domain\Authorization\Services\AuthorizationService::class => 'access_service',
                \App\Domain\Authorization\Services\PermissionResolver::class => 'permission_resolver',
                \App\Domain\Authorization\Services\ModulePermissionChecker::class => 'module_checker',
                \App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy::class => 'assistant_policy',
                \App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker::class => 'assistant_permissions',
                \App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotInputs::class => 'assistant_snapshot',
                \App\Services\Entitlements\OrganizationEntitlementService::class => 'entitlement_service',
                \App\BusinessModules\Features\AIAssistant\Http\Resources\ConversationCollection::class => 'conversation_collection',
                \App\BusinessModules\Features\AIAssistant\Services\ConversationManager::class => 'conversation_manager',
                \App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard::class => 'reference_guard',
                \App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantEstimatePositionReadService::class => 'estimate_positions',
                \App\BusinessModules\Features\AIAssistant\Services\AssistantProjectMaterialStockReader::class => 'project_material_stock',
                \App\BusinessModules\Features\AIAssistant\Services\AssistantProjectAttentionReader::class => 'project_attention',
                \App\BusinessModules\Features\AIAssistant\Services\AssistantProjectScheduleImpactReader::class => 'project_schedule',
                \App\BusinessModules\Features\AIAssistant\Services\AssistantProjectWorkforceReader::class => 'project_workforce',
                \App\BusinessModules\Features\AIAssistant\Services\AssistantProjectPaymentReader::class => 'project_payment',
                \App\BusinessModules\Features\AIAssistant\Services\AssistantProjectHandoverReader::class => 'project_handover',
                \App\BusinessModules\Features\AIAssistant\Services\AssistantProjectMachineryReader::class => 'project_machinery',
                \App\BusinessModules\Features\AIAssistant\Services\AssistantProjectContractRosterReader::class => 'project_contract_roster',
                \App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentReadinessReader::class => 'document_readiness',
                \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportReference::class => 'report_reference',
                \App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantCatalogLookupReader::class => 'catalog_lookup',
                \App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantContractSupplementaryReader::class => 'contract_supplementary',
                \App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationCreditBalanceReader::class => 'credit_balance',
                \App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationOperationalReader::class => 'organization_operational',
                \App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectOperationalReader::class => 'project_operational',
                \App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantTenderReader::class => 'tender_reader',
                \App\BusinessModules\Features\AIAssistant\Actions\Domains\DiscoverAssistantDomainCapabilitiesTool::class => 'domain_discover',
                \App\BusinessModules\Features\AIAssistant\Actions\Domains\SearchAssistantDocumentsTool::class => 'document_search',
                \App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\DescribeCustomReportSourceTool::class => 'report_describe',
                \App\BusinessModules\Features\AIAssistant\Services\AIAssistantService::class => 'assistant_service',
                \App\BusinessModules\Features\AIAssistant\Services\AssistantBimModelReader::class => 'bim_reader',
                \App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService::class => 'domain_reader',
                \App\BusinessModules\Features\AIAssistant\Services\AssistantIndexStatusService::class => 'index_status',
                \App\BusinessModules\Features\AIAssistant\Services\AssistantMaterialStockReader::class => 'material_stock',
                \App\BusinessModules\Features\AIAssistant\Services\AssistantProjectFinanceOverview::class => 'project_finance',
                \App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle::class => 'request_lifecycle',
                \App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentUnitReader::class => 'document_units',
                \App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantEstimateResolver::class => 'estimate_resolver',
                \App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\GetEstimatePositionsTool::class => 'estimate_get',
                \App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\SearchEstimatePositionsTool::class => 'estimate_search',
                \App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\ProjectPulseService::class => 'project_pulse',
                \App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever::class => 'rag_retriever',
                \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportBuilder::class => 'report_builder',
                \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportSourceHints::class => 'report_hints',
                \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportSubscriptionManager::class => 'report_subscription',
                \App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreBackendAuthorityFence::class => 'public_core_fence',
                \App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotBuilder::class => 'snapshot_builder',
            ];
            $entry = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2] ?? [];
            $caller = $callers[$entry['class'] ?? ''] ?? $caller;
            $measurement['callers'][$caller] = ($measurement['callers'][$caller] ?? 0) + 1;
        } catch (\Throwable) {
        }
    }

    public static function processingCheckpoint(Request $request): ?array
    {
        $metrics = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if (! $metrics instanceof self || ! $metrics->captureSources) {
            return null;
        }

        return ['metrics' => $metrics, 'started_at' => hrtime(true), 'sql_count' => $metrics->count,
            'sql_total_ms' => $metrics->total, 'process_cpu_ms' => self::processCpuMilliseconds()];
    }

    public static function recordRoleCatalogRedisGet(Request $request, CommandExecuted $event): void
    {
        $metrics = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if (! $metrics instanceof self || ! $metrics->captureSources
            || ! is_string($event->command) || strcasecmp($event->command, 'get') !== 0
            || ! is_array($event->parameters) || count($event->parameters) !== 1
            || ! is_string($event->parameters[0] ?? null) || strlen($event->parameters[0]) > 4096
            || ! str_ends_with($event->parameters[0], 'authorization_roles:v3')
            || (! is_float($event->time) && ! is_int($event->time)) || ! is_finite((float) $event->time) || $event->time < 0
            || ! is_finite($metrics->roleCatalogRedisGet['total_ms'] + $event->time)) {
            return;
        }

        $metrics->roleCatalogRedisGet['observed'] = true;
        $metrics->roleCatalogRedisGet['count']++;
        $metrics->roleCatalogRedisGet['total_ms'] += $event->time;
        $metrics->roleCatalogRedisGet['max_ms'] = max($metrics->roleCatalogRedisGet['max_ms'], $event->time);
    }

    public static function recordProcessingPhase(Request $request, string $phase, int $startedAt, ?array $checkpoint = null, ?string $entityType = null): void
    {
        $metrics = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if (! $metrics instanceof self || ! $metrics->captureSources || $startedAt < 0 || ! in_array($phase, [
            'list_prepare', 'list_encode', 'request_render', 'request_chain',
            'design_freshness', 'design_resources', 'design_response_build', 'design_response_decode',
            'journal_load', 'journal_prepare', 'journal_payload', 'journal_encode',
            'order_render', 'order_workflow', 'order_payment', 'order_chain',
            'file_report_list', 'file_report_classify', 'file_report_access',
            'file_report_storage_exists', 'file_report_storage_url',
            'admin_authorize', 'purchase_authorize', 'interface_access', 'procurement_modules', 'response_normalize',
            'rag_prepare', 'rag_schema_prefetch', 'rag_source_prepare', 'rag_source_acl', 'rag_source_counts',
            'rag_expected_counts', 'rag_documents', 'rag_finalize',
            'sql_tracing', 'redis_tracing',
            'current_access_check', 'role_catalog', 'rag_acl_discovery', 'rag_acl_batch_compile', 'rag_acl_finish',
            'current_access_evaluate', 'rag_acl_entity_build', 'rag_acl_register',
            'access_system_match', 'access_module_match', 'security_emit', 'security_prepare', 'security_write',
            'access_context_read', 'access_role_read', 'access_context_hierarchy', 'access_conditions',
            'access_module_roles', 'access_module_entitlement', 'access_module_permission',
            'pulse_actor', 'pulse_current', 'pulse_facts', 'pulse_access', 'pulse_rules',
            'pulse_synthesis', 'pulse_finance', 'pulse_write', 'pulse_format',
        ], true)) {
            return;
        }
        if ($checkpoint !== null && (($checkpoint['metrics'] ?? null) !== $metrics
            || ($checkpoint['started_at'] ?? null) !== $startedAt
            || ! is_int($checkpoint['sql_count'] ?? null) || $checkpoint['sql_count'] < 0 || $checkpoint['sql_count'] > $metrics->count
            || ! is_float($checkpoint['sql_total_ms'] ?? null) || ! is_finite($checkpoint['sql_total_ms'])
            || $checkpoint['sql_total_ms'] < 0 || $checkpoint['sql_total_ms'] > $metrics->total)) {
            return;
        }
        $milliseconds = (hrtime(true) - $startedAt) / 1_000_000;
        if (! is_finite($milliseconds) || $milliseconds < 0) {
            return;
        }
        $measurement = ['count' => 1, 'total_ms' => $milliseconds, 'max_ms' => $milliseconds];
        if ($checkpoint !== null) {
            $measurement['sql_count'] = $metrics->count - $checkpoint['sql_count'];
            $measurement['sql_total_ms'] = $metrics->total - $checkpoint['sql_total_ms'];
            $cpu = self::processCpuMilliseconds();
            $startedCpu = $checkpoint['process_cpu_ms'] ?? null;
            if ($cpu !== null && is_float($startedCpu) && is_finite($startedCpu) && $startedCpu >= 0 && $cpu >= $startedCpu) {
                $measurement['process_cpu_ms'] = $cpu - $startedCpu;
            }
        }
        $metrics->processingPhases[$phase] ??= [];
        self::addMeasurement($metrics->processingPhases[$phase], $measurement);
        if ($phase !== 'rag_acl_entity_build' || $entityType === null
            || preg_match('/^[a-z][a-z0-9_]{0,127}$/D', $entityType) !== 1) {
            return;
        }
        try {
            if (! array_key_exists($entityType, AssistantEntityAccessCatalog::entityDefinitions())) { return; }
        } catch (\Throwable) {
            return;
        }
        if (! isset($metrics->entityBuilds[$entityType]) && count($metrics->entityBuilds) >= self::MAX_ENTITY_BUILD_GROUPS - 1) {
            $entityType = '__other__';
        }
        $metrics->entityBuilds[$entityType] ??= [];
        self::addMeasurement($metrics->entityBuilds[$entityType], $measurement);
    }

    public function summary(): array
    {
        $groups = $this->groups;
        foreach ($groups as &$group) {
            $group['total_ms'] = round($group['total_ms'], 2);
        }

        $summary = [
            'sql_count' => $this->count,
            'sql_total_ms' => round($this->total, 2),
            'sql_max_ms' => round($this->maximum, 2),
            'sql_groups' => $groups,
            'release_sha' => RuntimeReleaseSha::current(),
        ];
        if ($this->captureSources) {
            $summary['access_reads'] = $this->accessReads;
            $summary['role_catalog_redis_get'] = $this->roleCatalogRedisGet;
            $summary['role_catalog_redis_get']['total_ms'] = round($this->roleCatalogRedisGet['total_ms'], 2);
            $summary['role_catalog_redis_get']['max_ms'] = round($this->roleCatalogRedisGet['max_ms'], 2);
            $summary['rag_entity_builds'] = $this->entityBuildSummary();
            $batches = $this->sourceBatches;
            ksort($batches);
            $summary['rag_source_batches'] = array_values($batches);
            $summary['rag_source_batches_dropped_count'] = $this->sourceBatchesDropped;
            $summary['sql_sources'] = array_map(static function (array $source): array {
                $source['total_ms'] = round($source['total_ms'], 2);
                $source['max_ms'] = round($source['max_ms'], 2);
                $source['distinct_variants'] = count($source['variants']);
                unset($source['variants']);

                return $source;
            }, array_values($this->sources));
            $summary['sql_sources_dropped_count'] = $this->sourcesDropped;
            $summary['sql_source_capture_ms'] = round($this->sourceCaptureMilliseconds, 2);
            $summary['processing_phases'] = array_map(static function (array $phase): array {
                $phase['total_ms'] = round($phase['total_ms'], 2);
                $phase['max_ms'] = round($phase['max_ms'], 2);
                if (isset($phase['sql_total_ms'])) {
                    $phase['sql_total_ms'] = round($phase['sql_total_ms'], 2);
                }
                if (isset($phase['process_cpu_ms'])) {
                    $phase['process_cpu_ms'] = round($phase['process_cpu_ms'], 2);
                }

                return $phase;
            }, $this->processingPhases);
        }
        if ($this->assistantSnapshot !== null) {
            $summary['assistant_snapshot'] = $this->assistantSnapshot;
        }
        if ($this->assistantSnapshotEpoch !== null) {
            $summary['assistant_snapshot_epoch'] = $this->assistantSnapshotEpoch;
        }

        return $summary;
    }

    private static function addMeasurement(array &$total, array $measurement): void
    {
        foreach ($measurement as $key => $value) {
            $total[$key] = $key === 'max_ms' ? max($total[$key] ?? 0, $value) : ($total[$key] ?? 0) + $value;
        }
    }

    private function entityBuildSummary(): array
    {
        $builds = $this->entityBuilds;
        $other = $builds['__other__'] ?? [];
        unset($builds['__other__']);
        uasort($builds, static fn (array $left, array $right): int => $right['total_ms'] <=> $left['total_ms']);
        foreach (array_slice($builds, self::MAX_REPORTED_ENTITY_BUILDS) as $build) {
            self::addMeasurement($other, $build);
        }
        $builds = array_slice($builds, 0, self::MAX_REPORTED_ENTITY_BUILDS, true);
        if ($other !== []) { $builds['__other__'] = $other; }
        $result = [];
        foreach ($builds as $type => $build) {
            foreach (['total_ms', 'max_ms', 'sql_total_ms', 'process_cpu_ms'] as $key) {
                if (isset($build[$key])) { $build[$key] = round($build[$key], 2); }
            }
            $result[] = ['entity_type' => $type] + $build;
        }

        return $result;
    }

    public function recordAssistantSnapshot(array $context): void
    {
        if (! in_array($context['phase'] ?? null, ['missing_release', 'ready', 'snapshot_rejected', 'snapshot_missing', 'fresh_read', 'fresh_read_unavailable'], true)
            || ! in_array($context['section'] ?? null, ['all', 'sources', 'documents'], true)) {
            return;
        }
        $snapshot = ['phase' => $context['phase'], 'section' => $context['section']];
        foreach (['release_sha' => 40, 'key_hash' => 64, 'cache_namespace_hash' => 64, 'queue_namespace_hash' => 64] as $field => $length) {
            $value = $context[$field] ?? null;
            $snapshot[$field] = is_string($value) && preg_match('/^[a-f0-9]{'.$length.'}$/D', $value) === 1 ? $value : null;
        }
        $age = $context['age_seconds'] ?? null;
        $snapshot['age_seconds'] = is_int($age) && $age >= 0 ? $age : null;
        $queued = $context['refresh_queued'] ?? null;
        $snapshot['refresh_queued'] = is_bool($queued) ? $queued : null;
        $this->assistantSnapshot = $snapshot;
    }

    private static function processCpuMilliseconds(): ?float
    {
        if (! function_exists('getrusage')) { return null; }
        try {
            $usage = getrusage();
            foreach (['ru_utime.tv_sec', 'ru_utime.tv_usec', 'ru_stime.tv_sec', 'ru_stime.tv_usec'] as $field) {
                if (! is_int($usage[$field] ?? null) || $usage[$field] < 0) { return null; }
            }
            $milliseconds = ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000.0
                + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1000.0;

            return is_finite($milliseconds) ? $milliseconds : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function recordAssistantSnapshotEpoch(string $phase, ?string $relation): void
    {
        if (! in_array($phase, ['state_rejected', 'transaction_rejected', 'schema_rejected', 'valid', 'relation_mutation_present', 'epoch_guard_rejected'], true)) {
            return;
        }
        $this->assistantSnapshotEpoch = [
            'phase' => $phase,
            'relation' => $relation !== null && strlen($relation) <= 63 && preg_match('/^[a-z_][a-z0-9_]*$/D', $relation) === 1 ? $relation : null,
        ];
    }

    private function recordSource(QueryExecuted $query, string $group): void
    {
        $source = SlowQueryContext::source(['app/Services/Monitoring/ApiQueryMetrics.php', 'app/Providers/TracingServiceProvider.php']);
        $key = $group.'|'.($source['file'] ?? '').'|'.($source['line'] ?? '');
        if (! isset($this->sources[$key])) {
            if (count($this->sources) >= self::MAX_SOURCE_GROUPS) {
                $this->sourcesDropped++;

                return;
            }
            $this->sources[$key] = ['group' => $group, 'source' => $source, 'count' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0,
                'sql_bytes_max' => 0, 'bindings_max' => 0,
                'variants' => [], 'distinct_variants_capped' => false, 'unclassified_variants' => 0];
        }
        $record = &$this->sources[$key];
        $record['count']++;
        $record['total_ms'] += (float) $query->time;
        $record['max_ms'] = max($record['max_ms'], (float) $query->time);
        $record['sql_bytes_max'] = max($record['sql_bytes_max'], strlen($query->sql));
        $record['bindings_max'] = max($record['bindings_max'], count($query->bindings));
        $variant = self::variant($query);
        if ($variant === null) {
            $record['unclassified_variants']++;

            return;
        }
        if (isset($record['variants'][$variant])) {
            return;
        }
        if (count($record['variants']) >= self::MAX_VARIANTS) {
            $record['distinct_variants_capped'] = true;

            return;
        }
        $record['variants'][$variant] = true;
    }

    private static function variant(QueryExecuted $query): ?string
    {
        $bytes = strlen($query->sql) + 1;
        if ($bytes > 32769 || count($query->bindings) > 1024) {
            return null;
        }
        $hash = hash_init('sha256');
        hash_update($hash, $query->sql."\0");
        foreach ($query->bindings as $key => $value) {
            if ((! is_scalar($value) && $value !== null) || (is_float($value) && ! is_finite($value))) {
                return null;
            }
            if ((is_string($value) && strlen($value) > 4096) || (is_string($key) && strlen($key) > 4096)) {
                return null;
            }
            $part = serialize($key).serialize($value)."\0";
            $bytes += strlen($part);
            if ($bytes > 65536) {
                return null;
            }
            hash_update($hash, $part);
        }

        return hash_final($hash);
    }
}
