<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Models\AssistantDocumentSettings;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagStatusBudgetExceeded;
use App\Models\User;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AssistantDocumentCoverageService
{
    public function __construct(private readonly AssistantDataAccessPolicy $policy, private readonly AssistantDocumentService $documents) {}

    public function coverage(int $organizationId, User $actor, ?callable $checkpoint = null, ?callable $checkDeadline = null): array
    {
        $guard = $checkDeadline ?? $checkpoint;
        if (! $this->policy->belongsToOrganization($actor, $organizationId)) throw new AccessDeniedHttpException;
        if ($checkpoint !== null) { $checkpoint(); }
        $canManage = $this->canManageSettings($organizationId, $actor);
        if ($checkpoint !== null) { $checkpoint(); }
        $files = $this->policy->accessibleFiles($actor, $organizationId, false);
        $operations = app(AssistantOperationsNativeFileAdapter::class);
        foreach (['safety_medical_exam', 'warehouse_item_gallery'] as $nativeFileType) {
            $files->whereNotIn('files.id', $operations->sourceQueryForActor($actor, $organizationId, $nativeFileType)
                ->select($nativeFileType === 'safety_medical_exam' ? 'native_file.id' : 'native_source.id'));
        }
        if ($checkpoint !== null) { $checkpoint(); }
        $documents = $this->policy->accessibleDocuments($actor, $organizationId);
        $types = [];
        if ($checkpoint !== null) { $checkpoint(); }
        foreach ((clone $files)->select('files.fileable_type')->distinct()->pluck('files.fileable_type') as $fileType) {
            $entityType = $this->policy->entityTypeForModel((string) $fileType);
            if ($entityType !== null) $types[(string) $fileType] = $entityType;
        }
        $latest = (clone $documents)->select([])->selectRaw('MAX(ai_assistant_documents.id)')->groupBy('file_id');
        $indexed = (clone $documents)->whereIn('ai_assistant_documents.id', $latest)->select('ai_assistant_documents.*');
        $units = DB::table('ai_assistant_document_units')->whereIn('document_id', (clone $indexed)->select('ai_assistant_documents.id'))
            ->select('document_id')->selectRaw("COUNT(*) AS processed_units, SUM(CASE WHEN unit_type = 'ocr_page' THEN 1 ELSE 0 END) AS ocr_completed_pages")->groupBy('document_id');
        $base = (clone $files)->leftJoinSub($indexed, 'document', function (JoinClause $join) use ($types): void {
            $join->on('document.file_id', '=', 'files.id')->on('document.storage_path', '=', 'files.path')
                ->whereRaw('document.parent_entity_id = CAST(files.fileable_id AS TEXT)');
            $join->where(function (JoinClause $parents) use ($types): void {
                $parents->whereRaw('1 = 0');
                foreach ($types as $fileType => $entityType) $parents->orWhere(function (JoinClause $branch) use ($fileType, $entityType): void {
                    $branch->where('files.fileable_type', $fileType)->where('document.parent_entity_type', $entityType);
                });
            });
        })->leftJoinSub($units, 'units', 'units.document_id', '=', 'document.id');
        $unsupported = $types === [] ? 'TRUE' : 'files.fileable_type NOT IN ('.implode(',', array_fill(0, count($types), '?')).')';
        $supportedFormat = "(LOWER(COALESCE(NULLIF(files.original_name, ''), files.name, '')) ~ '\\.(txt|csv|json|xml|pdf|xlsx|xls|docx|doc)$'
            OR COALESCE(files.mime_type, '') LIKE 'text/%'
            OR files.mime_type IN ('application/json', 'application/xml', 'application/pdf', 'image/jpeg', 'image/png', 'image/webp')
            OR files.mime_type ~ '^application/[a-z0-9.+-]+\\+xml$')";
        $state = "CASE WHEN files.disk IS DISTINCT FROM 's3' OR $unsupported OR NOT COALESCE($supportedFormat, FALSE) THEN 'unsupported'
            WHEN document.id IS NULL THEN 'pending'
            WHEN document.coverage_status = 'empty' OR document.last_error = 'ocr_empty' THEN 'empty'
            WHEN document.status IN ('failed', 'damaged') OR document.coverage_status = 'failed' THEN 'failed'
            WHEN document.status = 'unsupported' THEN 'unsupported'
            WHEN document.status = 'ready' AND COALESCE(BTRIM(document.extracted_text), '') = '' THEN 'empty'
            WHEN document.status = 'ready' THEN 'ready'
            WHEN document.status = 'ocr_approved' THEN 'ocr_processing'
            WHEN document.status = 'ocr_quote_required' THEN 'ocr_required'
            ELSE 'pending' END";
        $base->selectRaw($state.' AS coverage_state', array_keys($types))
            ->selectRaw("COALESCE(units.processed_units, 0) AS processed_units, COALESCE(units.ocr_completed_pages, 0) AS ocr_completed_pages,
                CASE WHEN (document.metadata->>'page_count') ~ '^[0-9]{1,6}$' THEN (document.metadata->>'page_count')::bigint ELSE 0 END AS total_pages");
        $aggregate = DB::query()->fromSub($base->toBase(), 'coverage')->selectRaw('COUNT(*) AS total');
        foreach (['ready', 'pending', 'ocr_required', 'ocr_processing', 'failed', 'unsupported', 'empty'] as $status) {
            $aggregate->selectRaw('COALESCE(SUM(CASE WHEN coverage_state = ? THEN 1 ELSE 0 END), 0) AS '.$status, [$status]);
        }
        foreach (['processed_units', 'total_pages', 'ocr_completed_pages'] as $metric) $aggregate->selectRaw('COALESCE(SUM('.$metric.'), 0) AS '.$metric);
        if ($checkpoint !== null) { $checkpoint(); }
        $result = $aggregate->first();
        $coverage = array_map(static fn ($value): int => (int) $value, (array) $result);
        $nativeCandidates = $this->nativeCandidateTypes($organizationId, $actor, $checkpoint, $guard);
        $nativeCoverage = [];
        $nativeMetadataCount = 0;
        foreach (\App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry::values('attachmentCoverageDefinitions') as $type => $definition) {
            if ($guard !== null) { $guard(); }
            if ($type === 'tender_file' && ! isset($nativeCandidates[$type])) { continue; }
            $native = $this->policy->entityQuery($actor, $organizationId, $type);
            if ($native === null) { continue; }
            if ($checkpoint !== null) { $checkpoint(); }
            $count = $native->count();
            if ($count === 0) { continue; }
            $nativeMetadataCount += $count;
            $coverage['total'] += $count;
            $status = $definition['status'];
            $coverage[$status] = ($coverage[$status] ?? 0) + $count;
            $nativeCoverage[$type] = $definition + ['expected_file_count' => $count, 'indexed_file_count' => 0, 'content_scope' => 'metadata_only'];
        }
        $unmappedNativeCount = 0;
        $nativeMappedCount = 0;
        foreach (AssistantSalesNativeFileMetadata::definitions() + AssistantOperationsNativeFileMetadata::definitions() as $type => $definition) {
            if ($guard !== null) { $guard(); }
            if (! isset($nativeCandidates[$type])) { continue; }
            $isOperations = isset(AssistantOperationsNativeFileMetadata::definitions()[$type]);
            if ($isOperations) {
                $expected = $operations->sourceQueryForActor($actor, $organizationId, $type);
            } else {
                $readable = $this->policy->entityContentQuery($actor, $organizationId, $type);
                if ($readable === null) { continue; }
                $expected = AssistantSalesNativeFileMetadata::sourceQuery($type, $organizationId)
                    ->whereIn('native_source.id', $readable->select($readable->getModel()->getQualifiedKeyName()))
                    ->whereNotNull(DB::raw(AssistantSalesNativeFileMetadata::versionExpressions($type)[$definition['path']]));
            }
            if ($checkpoint !== null) { $checkpoint(); }
            $expectedCount = $expected->count('native_source.id');
            if ($expectedCount === 0) { continue; }
            $nativeDocuments = (clone $documents)->whereNull('file_id')->where('parent_entity_type', $type)
                ->where('metadata->assistant_native_source', $isOperations ? AssistantOperationsNativeFileMetadata::SOURCE : AssistantSalesNativeFileMetadata::SOURCE);
            $latestNative = (clone $nativeDocuments)->select([])->selectRaw('MAX(ai_assistant_documents.id)')
                ->groupBy('parent_entity_type')->groupByRaw("ai_assistant_documents.metadata->>'native_source_id'");
            $nativeDocuments->whereIn('ai_assistant_documents.id', $latestNative);
            $nativeUnits = DB::table('ai_assistant_document_units')
                ->whereIn('document_id', (clone $nativeDocuments)->select('ai_assistant_documents.id'))
                ->select('document_id')
                ->selectRaw("COUNT(*) AS processed_units, SUM(CASE WHEN unit_type = 'ocr_page' THEN 1 ELSE 0 END) AS ocr_completed_pages")
                ->groupBy('document_id');
            $counts = (clone $nativeDocuments)->select('ai_assistant_documents.id', 'ai_assistant_documents.metadata')
                ->addSelect('ai_assistant_documents.coverage_status', 'ai_assistant_documents.last_error', 'ai_assistant_documents.status', 'ai_assistant_documents.extracted_text')
                ->toBase();
            $nativeRows = DB::query()->fromSub($counts, 'document')
                ->leftJoinSub($nativeUnits, 'units', 'units.document_id', '=', 'document.id')
                ->selectRaw("CASE
                WHEN coverage_status = 'empty' OR last_error = 'ocr_empty' THEN 'empty'
                WHEN status IN ('failed', 'damaged') OR coverage_status = 'failed' THEN 'failed'
                WHEN status = 'unsupported' THEN 'unsupported'
                WHEN status = 'ready' AND COALESCE(BTRIM(extracted_text), '') = '' THEN 'empty'
                WHEN status = 'ready' THEN 'ready'
                WHEN status = 'ocr_approved' THEN 'ocr_processing'
                WHEN status = 'ocr_quote_required' THEN 'ocr_required'
                ELSE 'pending' END AS native_coverage_state")
                ->selectRaw("COALESCE(units.processed_units, 0) AS processed_units, COALESCE(units.ocr_completed_pages, 0) AS ocr_completed_pages,
                    CASE WHEN (document.metadata->>'page_count') ~ '^[0-9]{1,6}$' THEN (document.metadata->>'page_count')::bigint ELSE 0 END AS total_pages");
            if ($checkpoint !== null) { $checkpoint(); }
            $nativeAggregate = DB::query()->fromSub($nativeRows, 'native_documents')
                ->select('native_coverage_state')->selectRaw('COUNT(*) AS file_count')
                ->selectRaw('COALESCE(SUM(processed_units), 0) AS processed_units')
                ->selectRaw('COALESCE(SUM(ocr_completed_pages), 0) AS ocr_completed_pages')
                ->selectRaw('COALESCE(SUM(total_pages), 0) AS total_pages')
                ->groupBy('native_coverage_state')->get();
            $mapped = 0;
            foreach ($nativeAggregate as $stateCount) {
                $state = $stateCount->native_coverage_state;
                $count = (int) $stateCount->file_count;
                $mapped += $count;
                $coverage[$state] = ($coverage[$state] ?? 0) + $count;
                $coverage['processed_units'] += (int) $stateCount->processed_units;
                $coverage['ocr_completed_pages'] += (int) $stateCount->ocr_completed_pages;
                $coverage['total_pages'] += (int) $stateCount->total_pages;
            }
            $nativeMappedCount += $mapped;
            $missing = max(0, $expectedCount - $mapped);
            $unmappedNativeCount += $missing;
            $coverage['total'] += $expectedCount;
            $coverage['needs_access_review'] = ($coverage['needs_access_review'] ?? 0) + $missing;
            $nativeCoverage[$type] = ['expected_file_count' => $expectedCount, 'indexed_file_count' => $mapped,
                'unmapped_file_count' => $missing, 'status' => $missing > 0 ? 'needs_access_review' : 'mapped',
                'manual_ingestion_available' => $missing > 0];
        }
        foreach (['design_artifact_version', ...AssistantNativeFileMetadata::types(), ...AssistantLegalNativeFileMetadata::types()] as $type) {
            if ($guard !== null) { $guard(); }
            if (! isset($nativeCandidates[$type])) { continue; }
            $native = $this->policy->entityContentQuery($actor, $organizationId, $type);
            if ($native === null) { continue; }
            $model = $native->getModel();
            $table = $model->getTable();
            $native = $model->newQuery()->whereIn($model->getQualifiedKeyName(), $native->select($model->getQualifiedKeyName()));
            if ($type === 'design_artifact_version') { $native->whereNotNull($table.'.source_file_path'); }
            if (isset(AssistantLegalNativeFileMetadata::definitions()[$type])) {
                $native->whereNotNull($table.'.'.AssistantLegalNativeFileMetadata::definitions()[$type]['path']);
            }
            if ($checkpoint !== null) { $checkpoint(); }
            $expectedNative = (clone $native)->count();
            if ($expectedNative === 0) { continue; }
            $mapped = (clone $files)->whereIn('files.fileable_type', [$model::class, $model->getMorphClass()])
                ->select([])->selectRaw('CAST(files.fileable_id AS TEXT)');
            if ($checkpoint !== null) { $checkpoint(); }
            $missing = $native->whereNotIn(DB::raw('CAST('.$model->getQualifiedKeyName().' AS TEXT)'), $mapped)->count();
            $unmappedNativeCount += $missing;
            $coverage['total'] += $missing;
            $coverage['needs_access_review'] = ($coverage['needs_access_review'] ?? 0) + $missing;
            $nativeCoverage[$type] = ['expected_file_count' => $expectedNative, 'unmapped_file_count' => $missing,
                'status' => $missing > 0 ? 'needs_access_review' : 'mapped', 'manual_ingestion_available' => $missing > 0];
        }
        if ($checkpoint !== null) { $checkpoint(); }
        $settings = AssistantDocumentSettings::query()->where('organization_id', $organizationId)->first();
        $scanFiles = (clone $files)->where('files.disk', 's3');
        $cursor = (int) ($settings?->last_file_id ?? 0);
        if ($checkpoint !== null) { $checkpoint(); }
        $scan = $scanFiles->select([])->selectRaw('COUNT(*) AS expected')
            ->selectRaw('COUNT(*) FILTER (WHERE files.id <= ?) AS scanned', [$cursor])
            ->selectRaw('MAX(files.id) FILTER (WHERE files.id <= ?) AS last_visible', [$cursor])
            ->selectRaw('MAX(files.updated_at) FILTER (WHERE files.id <= ?) AS completed_at', [$cursor])
            ->first();
        $expected = (int) $scan->expected;
        $scanned = (int) $scan->scanned;
        $lastVisible = (int) ($scan->last_visible ?? 0);
        $completed = $scanned === $expected;
        $completedAt = $completed ? $scan->completed_at : null;
        if (is_string($completedAt) && $completedAt !== '') $completedAt = \Carbon\CarbonImmutable::parse($completedAt)->toAtomString();

        return ['document_coverage' => $coverage, 'native_attachment_coverage' => $nativeCoverage, 'can_manage_document_settings' => $canManage,
            'archive_scan' => ['expected_file_count' => $expected + $nativeMetadataCount + $unmappedNativeCount + $nativeMappedCount, 'scanned_file_count' => $scanned + $nativeMetadataCount + $nativeMappedCount,
                'storage_unverified_file_count' => $nativeMetadataCount,
                'last_file_id' => $lastVisible, 'completed_at' => $completedAt,
                'processing' => ! $completed || $unmappedNativeCount > 0]];
    }

    public function canManageSettings(int $organizationId, User $actor): bool
    {
        try {
            $this->documents->assertOwner($actor, $organizationId);
            return true;
        } catch (RuntimeException $exception) {
            if ($exception instanceof QueryException || $exception instanceof RagStatusBudgetExceeded) { throw $exception; }
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function captureStatusProof(int $organizationId, User $actor, array $nativeTypes = [], int $maxIdentities = 10000, ?callable $checkpoint = null): array
    {
        $remaining = max(0, $maxIdentities);
        $checkpoint?->__invoke();
        $fileRows = $this->coverageFiles($actor, $organizationId)
            ->select(['files.id', 'files.path', 'files.fileable_type', 'files.fileable_id', 'files.disk', 'files.updated_at'])
            ->limit($remaining + 1)->get();
        $complete = $fileRows->count() <= $remaining;
        $fileRows = $fileRows->take($remaining);
        $remaining -= $fileRows->count();

        $checkpoint?->__invoke();
        $documentRows = $this->policy->accessibleDocuments($actor, $organizationId)
            ->select(['ai_assistant_documents.id', 'ai_assistant_documents.file_id', 'ai_assistant_documents.storage_path',
                'ai_assistant_documents.parent_entity_type', 'ai_assistant_documents.parent_entity_id', 'ai_assistant_documents.updated_at'])
            ->limit($remaining + 1)->get();
        $complete = $complete && $documentRows->count() <= $remaining;
        $documentRows = $documentRows->take($remaining);
        $remaining -= $documentRows->count();

        $entityIds = [];
        $contentIds = [];
        foreach (array_values(array_unique($nativeTypes)) as $type) {
            $checkpoint?->__invoke();
            $entity = $this->policy->entityQuery($actor, $organizationId, $type);
            if ($entity !== null) {
                $model = $entity->getModel();
                $ids = (clone $entity)->limit($remaining + 1)->pluck($model->getQualifiedKeyName())->map(static fn ($id): string => (string) $id)->all();
                $complete = $complete && count($ids) <= $remaining;
                $entityIds[$type] = array_slice($ids, 0, $remaining);
                $remaining -= count($entityIds[$type]);
            }
            $checkpoint?->__invoke();
            $content = $this->policy->entityContentQuery($actor, $organizationId, $type);
            if ($content !== null) {
                $model = $content->getModel();
                $ids = (clone $content)->limit($remaining + 1)->pluck($model->getQualifiedKeyName())->map(static fn ($id): string => (string) $id)->all();
                $complete = $complete && count($ids) <= $remaining;
                $contentIds[$type] = array_slice($ids, 0, $remaining);
                $remaining -= count($contentIds[$type]);
            }
        }

        return [
            '_complete' => $complete,
            'files' => $fileRows->mapWithKeys(static fn ($file): array => [(string) $file->id => [
                (string) $file->path, (string) $file->fileable_type, (string) $file->fileable_id,
                (string) $file->disk, (string) $file->updated_at,
            ]])->all(),
            'documents' => $documentRows->mapWithKeys(static fn ($document): array => [(string) $document->id => [
                (string) $document->file_id, (string) $document->storage_path, (string) $document->parent_entity_type,
                (string) $document->parent_entity_id, (string) $document->updated_at,
            ]])->all(),
            'entities' => $entityIds,
            'content_entities' => $contentIds,
        ];
    }

    public function validateStatusProof(int $organizationId, User $actor, array $proof, ?callable $checkpoint = null): bool
    {
        $expectedFiles = $proof['files'] ?? null;
        $expectedDocuments = $proof['documents'] ?? null;
        if (! is_array($expectedFiles) || ! is_array($expectedDocuments)
            || ! $this->matchesFileProof($organizationId, $actor, $expectedFiles, $checkpoint)
            || ! $this->matchesDocumentProof($organizationId, $actor, $expectedDocuments, $checkpoint)) {
            return false;
        }

        foreach (['entities' => 'entityQuery', 'content_entities' => 'entityContentQuery'] as $group => $method) {
            foreach (($proof[$group] ?? []) as $type => $ids) {
                $checkpoint?->__invoke();
                if (! is_string($type) || ! is_array($ids)) {
                    return false;
                }
                if ($ids === []) {
                    continue;
                }
                $query = $group === 'entities'
                    ? $this->policy->entityQuery($actor, $organizationId, $type)
                    : $this->policy->entityContentQuery($actor, $organizationId, $type);
                if ($query === null) {
                    return false;
                }
                $model = $query->getModel();
                $visible = array_values(array_unique((clone $query)->whereKey($ids)->pluck($model->getQualifiedKeyName())
                    ->map(static fn ($id): string => (string) $id)->all()));
                $expectedIds = array_values(array_unique(array_map('strval', $ids)));
                sort($visible);
                sort($expectedIds);
                if ($visible !== $expectedIds) {
                    return false;
                }
            }
        }

        return true;
    }

    private function matchesFileProof(int $organizationId, User $actor, array $proof, ?callable $checkpoint): bool
    {
        if ($proof === []) {
            return true;
        }
        $checkpoint?->__invoke();
        $rows = $this->coverageFiles($actor, $organizationId)->whereIn('files.id', array_keys($proof))
            ->select(['files.id', 'files.path', 'files.fileable_type', 'files.fileable_id', 'files.disk', 'files.updated_at'])
            ->get()->mapWithKeys(static fn ($file): array => [(string) $file->id => [
                (string) $file->path, (string) $file->fileable_type, (string) $file->fileable_id,
                (string) $file->disk, (string) $file->updated_at,
            ]])->all();

        ksort($rows);
        ksort($proof);

        return $rows === $proof;
    }

    private function matchesDocumentProof(int $organizationId, User $actor, array $proof, ?callable $checkpoint): bool
    {
        if ($proof === []) {
            return true;
        }
        $checkpoint?->__invoke();
        $rows = $this->policy->accessibleDocuments($actor, $organizationId)->whereIn('ai_assistant_documents.id', array_keys($proof))
            ->select(['ai_assistant_documents.id', 'ai_assistant_documents.file_id', 'ai_assistant_documents.storage_path',
                'ai_assistant_documents.parent_entity_type', 'ai_assistant_documents.parent_entity_id', 'ai_assistant_documents.updated_at'])
            ->get()->mapWithKeys(static fn ($document): array => [(string) $document->id => [
                (string) $document->file_id, (string) $document->storage_path, (string) $document->parent_entity_type,
                (string) $document->parent_entity_id, (string) $document->updated_at,
            ]])->all();

        ksort($rows);
        ksort($proof);

        return $rows === $proof;
    }

    private function coverageFiles(User $actor, int $organizationId): \Illuminate\Database\Eloquent\Builder
    {
        $files = $this->policy->accessibleFiles($actor, $organizationId, false);
        $operations = app(AssistantOperationsNativeFileAdapter::class);
        foreach (['safety_medical_exam', 'warehouse_item_gallery'] as $nativeFileType) {
            $files->whereNotIn('files.id', $operations->sourceQueryForActor($actor, $organizationId, $nativeFileType)
                ->select($nativeFileType === 'safety_medical_exam' ? 'native_file.id' : 'native_source.id'));
        }

        return $files;
    }

    private function nativeCandidateTypes(int $organizationId, User $actor, ?callable $checkpoint, ?callable $guard): array
    {
        $queries = [
            'tender_file' => DB::table('tender_files as native_source')
                ->join('tenders as native_parent', 'native_parent.id', '=', 'native_source.tender_id')
                ->where('native_parent.organization_id', $organizationId),
            'design_artifact_version' => DB::table('design_artifact_versions')->where('organization_id', $organizationId)->whereNotNull('source_file_path'),
            AssistantNativeFileMetadata::ENTITY_TYPE => DB::table('workforce_export_package_files')->where('organization_id', $organizationId),
        ];
        foreach (AssistantLegalNativeFileMetadata::definitions() as $type => $definition) {
            $queries[$type] = DB::table($definition['table'])->where('organization_id', $organizationId)->whereNotNull($definition['path']);
        }
        foreach (AssistantSalesNativeFileMetadata::definitions() as $type => $definition) {
            $queries[$type] = AssistantSalesNativeFileMetadata::sourceQuery($type, $organizationId);
        }
        foreach (AssistantOperationsNativeFileMetadata::definitions() as $type => $definition) {
            $queries[$type] = AssistantOperationsNativeFileMetadata::sourceQuery($type, $organizationId);
        }
        $union = null;
        foreach ($queries as $type => $query) {
            if ($guard !== null) { $guard(); }
            $domain = $this->policy->domainForEntity($type);
            if ($domain !== null && ! $this->policy->canReadDomain($actor, $organizationId, $domain)) { continue; }
            $branch = DB::query()->selectRaw('? AS type', [$type])->whereExists($query->selectRaw('1'));
            $union = $union instanceof QueryBuilder ? $union->unionAll($branch) : $branch;
        }
        if (! $union instanceof QueryBuilder) { return []; }
        if ($checkpoint !== null) { $checkpoint(); }

        return array_fill_keys($union->pluck('type')->all(), true);
    }
}
