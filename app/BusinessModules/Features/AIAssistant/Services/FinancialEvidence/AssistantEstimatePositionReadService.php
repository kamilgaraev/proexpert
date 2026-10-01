<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemResource;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

final class AssistantEstimatePositionReadService
{
    private const SELECTOR_LIMIT = 20;

    private const CROSS_ESTIMATE_PAGE_SIZE = 12;

    private const CROSS_ESTIMATE_CANDIDATE_LIMIT = 200;

    private const POSITION_SCALES = [
        'unit_price' => 4,
        'current_unit_price' => 4,
        'total_amount' => 2,
        'current_total_amount' => 2,
        'direct_costs' => 4,
        'materials_cost' => 4,
        'machinery_cost' => 4,
        'labor_cost' => 4,
        'equipment_cost' => 4,
        'overhead_amount' => 2,
        'profit_amount' => 2,
    ];

    public function __construct(
        private readonly AssistantDataAccessPolicy $access,
    ) {}

    public function resolveSelector(string $selector, int $organizationId, User $actor): array
    {
        $selector = trim($selector);
        Validator::make(compact('selector'), ['selector' => ['required', 'string', 'min:1', 'max:255']])->validate();

        return DB::transaction(function () use ($selector, $organizationId, $actor): array {
            if (DB::transactionLevel() === 1) {
                DB::connection()->getPdo()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            return $this->access->withCurrentChecks($actor, $organizationId, function (AuthorizationService $authorization) use ($selector, $organizationId, $actor): array {
                if (! $this->access->canReadDomain($actor, $organizationId, 'estimates')
                    || ! $this->access->canCurrentPermission($actor, $organizationId, 'budget-estimates.finance.view')) {
                    throw new AuthorizationException;
                }
                $scoped = $this->access->entityQuery($actor, $organizationId, 'estimate');
                if ($scoped === null) {
                    return $this->resolution([], 0);
                }
                $numberId = Estimate::query()->where('organization_id', $organizationId)->where('number', $selector)->toBase()->value('id');
                $matching = Estimate::query()->where('organization_id', $organizationId)
                    ->whereIn('estimates.id', $scoped->select('estimates.id'));
                if ($numberId === null) {
                    $matching->where('estimates.name', $selector);
                } else {
                    $matching->whereKey((int) $numberId);
                }
                $candidates = $matching->select(['estimates.id', 'estimates.number', 'estimates.name', 'estimates.project_id'])
                    ->orderBy('estimates.id')->limit(self::SELECTOR_LIMIT + 1)->get();
                if ($candidates->count() > self::SELECTOR_LIMIT) {
                    return $this->resolution([], null, true);
                }
                $projectFinance = [];
                $options = [];
                foreach ($candidates as $estimate) {
                    $projectId = $estimate->project_id === null ? null : (int) $estimate->project_id;
                    if ($projectId !== null) {
                        $projectFinance[$projectId] ??= $this->canReadProjectFinance($authorization, $actor, $organizationId, $projectId);
                        if (! $projectFinance[$projectId]) {
                            if ($numberId !== null) {
                                throw new AuthorizationException;
                            }

                            continue;
                        }
                    }
                    $options[] = ['id' => (int) $estimate->id, 'number' => (string) $estimate->number,
                        'name' => (string) $estimate->name, 'project_id' => $projectId];
                }

                return $this->resolution($options, count($options));
            }, true);
        });
    }

    public function searchAcrossEstimates(
        string $search,
        int $organizationId,
        User $actor,
        int $perPage = self::CROSS_ESTIMATE_PAGE_SIZE,
        ?string $cursor = null,
    ): array {
        $search = trim($search);
        Validator::make(compact('search', 'organizationId', 'perPage', 'cursor'), [
            'search' => ['required', 'string', 'max:200'],
            'organizationId' => ['required', 'integer', 'min:1'],
            'perPage' => ['required', 'integer', 'between:1,'.self::CROSS_ESTIMATE_PAGE_SIZE],
            'cursor' => ['nullable', 'string', 'max:2048'],
        ])->validate();
        $searchTerms = AssistantEstimateCrossSearchIntent::normalizeSearchTerms($search);
        $exactCode = AssistantEstimateCrossSearchIntent::exactPositionCode($search);

        return DB::transaction(function () use ($searchTerms, $exactCode, $organizationId, $actor, $perPage, $cursor): array {
            if (DB::transactionLevel() === 1) {
                DB::connection()->getPdo()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            return $this->access->withCurrentChecks($actor, $organizationId, function (AuthorizationService $authorization) use (
                $searchTerms, $exactCode, $organizationId, $actor, $perPage, $cursor,
            ): array {
                if (! $this->access->canReadDomain($actor, $organizationId, 'estimates')
                    || ! $this->access->canCurrentPermission($actor, $organizationId, 'budget-estimates.finance.view')) {
                    throw new AuthorizationException;
                }

                $fetchedAt = now()->toIso8601String();
                if ($searchTerms === null && $exactCode === null) {
                    if ($cursor !== null) {
                        throw ValidationException::withMessages(['cursor' => [trans_message('ai_assistant_financial.unverified_claim')]]);
                    }

                    return ['matches' => [], 'fetched_at' => $fetchedAt, 'search_status' => 'no_search_terms',
                        'search_complete' => false, 'has_more' => false, 'next_cursor' => null];
                }

                $searchIdentity = $exactCode === null ? (string) $searchTerms : 'code:'.$exactCode;
                $afterPositionId = $this->decodeSearchCursor($cursor, $searchIdentity, $organizationId, $actor);
                $estimateScope = $this->access->entityQuery($actor, $organizationId, 'estimate');
                if ($estimateScope === null) {
                    return ['matches' => [], 'fetched_at' => $fetchedAt, 'search_status' => 'no_matches',
                        'search_complete' => true, 'has_more' => false, 'next_cursor' => null];
                }

                $candidates = EstimateItem::query()
                    ->join('estimates', 'estimates.id', '=', 'estimate_items.estimate_id')
                    ->where('estimates.organization_id', $organizationId)
                    ->where(static function (Builder $scope) use ($organizationId): void {
                        $scope->whereNull('estimates.project_id')->orWhereExists(static function ($projects) use ($organizationId): void {
                            $projects->selectRaw('1')->from('projects')->whereColumn('projects.id', 'estimates.project_id')
                                ->where('projects.organization_id', $organizationId);
                        });
                    })
                    ->whereIn('estimates.id', $estimateScope->select('estimates.id'))
                    ->where('estimate_items.id', '>', $afterPositionId ?? 0)
                    ->where(function (Builder $searchQuery) use ($searchTerms, $exactCode): void {
                        if ($exactCode !== null) {
                            $searchQuery->whereRaw('upper(estimate_items.normative_rate_code) = ?', [$exactCode]);

                            return;
                        }

                        $searchQuery->whereRaw("to_tsvector('russian', COALESCE(estimate_items.name, '')) @@ plainto_tsquery('russian', ?)", [$searchTerms]);
                    })
                    ->select([
                        'estimate_items.id as position_id', 'estimate_items.estimate_id',
                        'estimate_items.position_number', 'estimate_items.name as position_name',
                        'estimate_items.normative_rate_code as position_code',
                        'estimate_items.updated_at as position_updated_at',
                        'estimates.number as estimate_number', 'estimates.name as estimate_name',
                        'estimates.project_id', 'estimates.updated_at as estimate_updated_at',
                    ])
                    ->orderBy('estimate_items.id')
                    ->sharedLock()
                    ->limit(self::CROSS_ESTIMATE_CANDIDATE_LIMIT + 1)
                    ->toBase()
                    ->get();

                $matches = [];
                $projectFinance = [];
                $lastScannedPositionId = $afterPositionId ?? 0;
                $scanned = 0;
                $knownMore = false;
                foreach ($candidates as $candidate) {
                    if ($scanned >= self::CROSS_ESTIMATE_CANDIDATE_LIMIT) {
                        break;
                    }

                    $projectId = $candidate->project_id === null ? null : (int) $candidate->project_id;
                    $canReadProject = $projectId === null;
                    if ($projectId !== null) {
                        $projectFinance[$projectId] ??= $this->canReadProjectFinance($authorization, $actor, $organizationId, $projectId);
                        $canReadProject = $projectFinance[$projectId];
                    }

                    if ($canReadProject && count($matches) >= $perPage) {
                        $knownMore = true;
                        break;
                    }

                    $lastScannedPositionId = (int) $candidate->position_id;
                    $scanned++;
                    if (! $canReadProject) {
                        continue;
                    }

                    $estimateId = (int) $candidate->estimate_id;
                    $positionId = (int) $candidate->position_id;
                    $estimate = ['id' => $estimateId, 'number' => (string) $candidate->estimate_number,
                        'name' => (string) $candidate->estimate_name, 'project_id' => $projectId];
                    $position = ['id' => $positionId, 'estimate_id' => $estimateId,
                        'position_number' => (string) $candidate->position_number, 'name' => (string) $candidate->position_name,
                        'normative_rate_code' => $candidate->position_code === null ? null : (string) $candidate->position_code];
                    $position['version'] = hash('sha256', json_encode([
                        'estimate_id' => $estimateId, 'position' => $position,
                        'updated_at' => (string) $candidate->position_updated_at,
                    ], JSON_THROW_ON_ERROR));

                    $matches[] = ['estimate' => $estimate, 'position' => $position,
                        'estimate_updated_at' => (string) $candidate->estimate_updated_at,
                        'position_updated_at' => (string) $candidate->position_updated_at];
                }

                $searchComplete = ! $knownMore && $candidates->count() <= $scanned;
                $hasMore = ! $searchComplete;
                $nextCursor = $hasMore
                    ? $this->encodeSearchCursor($lastScannedPositionId, $searchIdentity, $organizationId, $actor)
                    : null;

                return ['matches' => $matches, 'fetched_at' => $fetchedAt,
                    'search_status' => $searchComplete ? ($matches === [] ? 'no_matches' : 'matches') : 'partial',
                    'search_complete' => $searchComplete, 'has_more' => $hasMore, 'next_cursor' => $nextCursor];
            }, true);
        });
    }

    private function encodeSearchCursor(int $positionId, string $searchTerms, int $organizationId, User $actor): string
    {
        return Crypt::encryptString(json_encode([
            'position_id' => $positionId,
            'search_hash' => hash('sha256', $searchTerms),
            'organization_id' => $organizationId,
            'actor_id' => (int) $actor->id,
        ], JSON_THROW_ON_ERROR));
    }

    private function decodeSearchCursor(?string $cursor, string $searchTerms, int $organizationId, User $actor): ?int
    {
        if ($cursor === null) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw ValidationException::withMessages(['cursor' => [trans_message('ai_assistant_financial.unverified_claim')]]);
        }

        if (! is_array($payload) || ! is_int($payload['position_id'] ?? null) || $payload['position_id'] < 0
            || ($payload['search_hash'] ?? null) !== hash('sha256', $searchTerms)
            || ($payload['organization_id'] ?? null) !== $organizationId
            || ($payload['actor_id'] ?? null) !== (int) $actor->id) {
            throw ValidationException::withMessages(['cursor' => [trans_message('ai_assistant_financial.unverified_claim')]]);
        }

        return $payload['position_id'];
    }

    public function canReadReference(User $actor, int $organizationId, array $reference): bool
    {
        return $this->access->withCurrentChecks($actor, $organizationId, function (AuthorizationService $authorization) use ($actor, $organizationId, $reference): bool {
            $type = $reference['entity_type'] ?? $reference['entityType'] ?? $reference['type'] ?? null;
            $id = $reference['entity_id'] ?? $reference['entityId'] ?? $reference['id'] ?? null;
            if (! in_array($type, ['estimate', 'estimate_item', 'estimate_item_resource'], true)
                || (! is_int($id) && ! is_string($id))
                || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                || ! $this->access->canCurrentPermission($actor, $organizationId, 'budget-estimates.finance.view')) {
                return false;
            }
            $query = $this->access->entityQuery($actor, $organizationId, 'estimate');
            if ($query === null) {
                return false;
            }
            if ($type === 'estimate') {
                $query->whereKey((int) $id);
            } else {
                $items = EstimateItem::query();
                if ($type === 'estimate_item') {
                    $items->whereKey((int) $id);
                    if (array_key_exists('parent_work_id', $reference)) {
                        $parentWorkId = filter_var($reference['parent_work_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        $referenceEstimateId = filter_var($reference['estimate_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        $referenceOrganizationId = filter_var($reference['organization_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        $referenceEstimateItemId = filter_var($reference['estimate_item_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        $referenceEntityId = filter_var($reference['entity_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        $referenceProjectId = $reference['project_id'] ?? null;
                        if ($parentWorkId === false || $referenceEstimateId === false || $referenceOrganizationId !== $organizationId
                            || $referenceEntityId === false || $referenceEstimateItemId !== $referenceEntityId || $referenceEntityId !== (int) $id
                            || $parentWorkId === $referenceEntityId || ($reference['composition_scope'] ?? null) !== 'resources'
                            || ! array_key_exists('project_id', $reference)
                            || ($referenceProjectId !== null && filter_var($referenceProjectId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
                            return false;
                        }
                        $items->whereKey($referenceEntityId)->where('estimate_id', $referenceEstimateId)->where('parent_work_id', $parentWorkId)
                            ->whereHas('parentWork', static fn (Builder $parent): Builder => $parent->where('estimate_id', $referenceEstimateId)->whereNull('parent_work_id'));
                    }
                } else {
                    $resource = EstimateItemResource::query()->whereKey((int) $id);
                    if (array_key_exists('composition_scope', $reference) || array_key_exists('finance_representation', $reference)) {
                        $representation = $reference['finance_representation'] ?? null;
                        if (($reference['composition_scope'] ?? null) !== 'resources' || ! is_string($representation) || $representation === '') {
                            return false;
                        }
                        $resource->where('finance_representation', $representation);
                        if ($representation === 'independent') {
                            $resource->whereNull('represented_by_item_id');
                        }
                    }
                    $items->whereIn('id', $resource->select('estimate_item_id'));
                    if (isset($reference['estimate_item_id'])) {
                        $items->whereKey($reference['estimate_item_id']);
                    }
                }
                $query->whereIn('estimates.id', $items->select('estimate_id'));
            }
            if (isset($reference['estimate_id'])) {
                $query->whereKey($reference['estimate_id']);
            }
            $estimate = $query->select(['estimates.id', 'estimates.project_id'])->first();
            if ($estimate === null || (isset($reference['organization_id']) && (int) $reference['organization_id'] !== $organizationId)) {
                return false;
            }
            $projectId = $estimate->project_id === null ? null : (int) $estimate->project_id;
            $projectKey = array_key_exists('project_id', $reference) ? 'project_id' : (array_key_exists('projectId', $reference) ? 'projectId' : null);
            if ($projectKey !== null && ($reference[$projectKey] === null ? null : (int) $reference[$projectKey]) !== $projectId) {
                return false;
            }

            return $projectId === null || $this->canReadProjectFinance($authorization, $actor, $organizationId, $projectId);
        });
    }

    private function resolution(array $options, ?int $total, bool $limited = false): array
    {
        return ['status' => $limited ? 'ambiguous' : ($total === 1 ? 'resolved' : ($total === 0 ? 'not_found' : 'ambiguous')),
            'estimate_id' => $total === 1 ? $options[0]['id'] : null,
            'options' => $options, 'total' => $total, 'has_more' => false, 'limited' => $limited,
            'explicit_selection' => true,
            'message' => $total === 1 ? null : trans_message($total === 0 ? 'ai_assistant_financial.not_found' : 'ai_assistant_financial.ambiguous')];
    }

    private function canReadProjectFinance(AuthorizationService $authorization, User $actor, int $organizationId, int $projectId): bool
    {
        return $authorization->canCurrent($actor, 'budget-estimates.finance.view', [
            'context_type' => 'project', 'project_id' => $projectId, 'organization_id' => $organizationId,
        ]);
    }

    public function page(
        int $estimateId,
        int $organizationId,
        User $actor,
        int $page = 1,
        int $perPage = 20,
        ?string $query = null,
        ?int $positionId = null,
        bool $includeComposition = false,
        int $compositionPage = 1,
        int $compositionPerPage = 20,
        ?string $positionNumber = null,
    ): array {
        $positionNumber = $positionNumber === null ? null : trim($positionNumber);
        Validator::make(compact('estimateId', 'organizationId', 'page', 'perPage', 'query', 'positionId', 'compositionPage', 'compositionPerPage', 'positionNumber'), [
            'estimateId' => ['required', 'integer', 'min:1'],
            'organizationId' => ['required', 'integer', 'min:1'],
            'page' => ['required', 'integer', 'between:1,1000000'],
            'perPage' => ['required', 'integer', 'between:1,100'],
            'query' => ['nullable', 'string', 'max:200'],
            'positionId' => ['nullable', 'integer', 'min:1'],
            'positionNumber' => ['nullable', 'string', 'min:1', 'max:50'],
            'compositionPage' => ['required', 'integer', 'between:1,1000000'],
            'compositionPerPage' => ['required', 'integer', 'between:1,20'],
        ])->validate();
        $query = $query === null ? null : trim($query);

        return DB::transaction(function () use (
            $estimateId,
            $organizationId,
            $actor,
            $page,
            $perPage,
            $query,
            $positionId,
            $includeComposition,
            $compositionPage,
            $compositionPerPage,
            $positionNumber,
        ): array {
            if (DB::transactionLevel() === 1) {
                DB::connection()->getPdo()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            return $this->access->withCurrentChecks($actor, $organizationId, function (AuthorizationService $authorization) use (
                $estimateId, $organizationId, $actor, $page, $perPage, $query, $positionId,
                $includeComposition, $compositionPage, $compositionPerPage, $positionNumber,
            ): array {
                if (! $this->access->canReadEntityContent($actor, $organizationId, 'estimate', $estimateId)) {
                    throw new AuthorizationException;
                }
                $estimate = Estimate::query()->where('organization_id', $organizationId)->sharedLock()->findOrFail($estimateId);
                if ($estimate->project_id !== null && ! $this->canReadProjectFinance($authorization, $actor, $organizationId, (int) $estimate->project_id)) {
                    throw new AuthorizationException;
                }

                $matches = EstimateItem::query()->where('estimate_id', $estimateId);
                $this->applySelection($matches, $query, $positionId, $positionNumber);
                $matchingCount = (clone $matches)->count();
                $totalCount = EstimateItem::query()->where('estimate_id', $estimateId)->count();
                $offset = ($page - 1) * $perPage;
                $loaded = (clone $matches)->with(['measurementUnit' => static fn (BelongsTo $units): BelongsTo => $units->where('organization_id', $organizationId)])
                    ->orderBy('id')->sharedLock()
                    ->offset($offset)->limit($perPage + 1)->get();
                $hasMore = $loaded->count() > $perPage;
                $items = $loaded->take($perPage)->values();
                $excluded = $this->effectiveExclusions($items, $estimateId);
                $fetchedAt = now()->toIso8601String();
                $positions = [];

                foreach ($items as $item) {
                    $isExcluded = $excluded[(int) $item->id] ?? (bool) $item->is_not_accounted;
                    $position = [
                        'id' => (int) $item->id,
                        'position_number' => (string) $item->position_number,
                        'name' => (string) $item->name,
                        'normative_rate_code' => $item->normative_rate_code,
                        'item_type' => (string) $item->getRawOriginal('item_type'),
                        'parent_work_id' => $item->parent_work_id === null ? null : (int) $item->parent_work_id,
                        'unit' => $item->measurementUnit?->short_name,
                        'quantity' => FinanceDecimal::value((string) ($item->quantity_total ?? $item->quantity ?? '0'), 8),
                        'excluded' => $isExcluded,
                        'included_in_total' => ! $isExcluded && $item->parent_work_id === null,
                    ];
                    foreach (self::POSITION_SCALES as $field => $scale) {
                        $position[$field] = $item->$field === null ? null : FinanceDecimal::value((string) $item->$field, $scale);
                    }
                    $position['version'] = hash('sha256', json_encode($position, JSON_THROW_ON_ERROR));
                    $position['navigation'] = ['url' => '/estimates/'.$estimateId.'?position_id='.$item->id];
                    $positions[] = $position;
                }

                $header = [
                    'id' => $estimateId,
                    'number' => (string) $estimate->number,
                    'name' => (string) $estimate->name,
                    'status' => (string) $estimate->status,
                    'estimate_date' => $estimate->estimate_date?->toDateString(),
                    'project_id' => $estimate->project_id === null ? null : (int) $estimate->project_id,
                    'version' => $estimate->version,
                    'vat_rate' => $estimate->vat_rate === null ? null : (string) $estimate->vat_rate,
                ];
                $version = hash('sha256', json_encode([
                    'scope' => 'returned_positions_page',
                    'estimate' => $header,
                    'query' => $query,
                    'position_id' => $positionId,
                    'page' => $page,
                    'per_page' => $perPage,
                    'matching_count' => $matchingCount,
                    'total_count' => $totalCount,
                    'position_number' => $positionNumber,
                    'positions' => $positions,
                ], JSON_THROW_ON_ERROR));
                $source = [
                    'source_type' => 'estimate',
                    'entity_type' => 'estimate',
                    'entity_id' => $estimateId,
                    'organization_id' => $organizationId,
                    'project_id' => $header['project_id'],
                    'version' => $version,
                    'fetched_at' => $fetchedAt,
                    'position_count' => $matchingCount,
                    'total_position_count' => $totalCount,
                    'validation_scope' => 'returned_positions_page',
                    'content_scope' => 'structured',
                    'checked_fields' => ['number', 'name', 'status', 'estimate_date'],
                    'required_permissions' => ['budget-estimates.view', 'budget-estimates.finance.view'],
                    'required_domains' => ['estimates'],
                    'source_version' => (string) $estimate->getRawOriginal('updated_at'),
                    'navigation' => ['url' => '/estimates/'.$estimateId],
                ];
                $evidence = [
                    'estimate' => $header,
                    'positions' => $positions,
                    'position_count' => $matchingCount,
                    'total_position_count' => $totalCount,
                    'fetched_at' => $fetchedAt,
                    'version' => $version,
                    'validation_status' => 'partial',
                    'validation_scope' => 'returned_positions_page',
                    'source_refs' => [$source],
                ];

                $composition = null;
                if ($includeComposition) {
                    $composition = $items->count() === 1 && $matchingCount === 1
                        ? $this->resources($items->first(), $estimate, $organizationId, $fetchedAt, $compositionPage, $compositionPerPage)
                        : ['status' => 'select_one_position', 'items' => [], 'total' => $matchingCount,
                            'page' => 1, 'per_page' => 0, 'has_more' => false, 'next_page' => null];
                }
                if (($composition['status'] ?? null) === 'returned') {
                    $evidence['validation_scope'] = 'returned_positions_and_resources';
                    $evidence['source_refs'][0]['validation_scope'] = 'returned_positions_and_resources';
                }

                return [
                    'evidence' => $evidence,
                    'meta' => [
                        'page' => $page,
                        'per_page' => $perPage,
                        'total' => $matchingCount,
                        'total_estimate_positions' => $totalCount,
                        'has_more' => $hasMore,
                        'next_page' => $hasMore ? $page + 1 : null,
                    ],
                    'composition' => $composition,
                ];
            }, true);
        });
    }

    private function applySelection(Builder $query, ?string $search, ?int $positionId, ?string $positionNumber): void
    {
        if ($positionId !== null) {
            $query->whereKey($positionId);
        }
        if ($positionNumber !== null && $positionNumber !== '') {
            $query->where('position_number', $positionNumber);
        }
        if ($search === null || $search === '') {
            return;
        }
        $positionNumber = preg_match('/^[\pL\pN][\pL\pN._\/\-]*$/uD', $search) === 1 ? $search : null;
        $query->where(function (Builder $filter) use ($search, $positionNumber): void {
            $filter->whereRaw("to_tsvector('russian', COALESCE(estimate_items.name, '')) @@ plainto_tsquery('russian', ?)", [$search]);
            if ($positionNumber !== null) {
                $filter->orWhere('position_number', $positionNumber)->orWhere('normative_rate_code', $positionNumber);
            }
        });
    }

    private function effectiveExclusions(iterable $items, int $estimateId): array
    {
        $excluded = [];
        $parentsById = [];
        $frontier = [];
        foreach ($items as $item) {
            $excluded[(int) $item->id] = (bool) $item->is_not_accounted;
            $parentsById[(int) $item->id] = [
                'parent_work_id' => $item->parent_work_id === null ? null : (int) $item->parent_work_id,
                'excluded' => (bool) $item->is_not_accounted,
            ];
            if ($item->parent_work_id !== null) {
                $frontier[] = (int) $item->parent_work_id;
            }
        }
        $seen = [];
        while ($frontier !== []) {
            $ids = array_values(array_diff(array_unique($frontier), array_keys($seen)));
            if ($ids === []) {
                break;
            }
            $frontier = [];
            foreach ($ids as $id) {
                $seen[$id] = true;
            }
            $parents = EstimateItem::query()->where('estimate_id', $estimateId)->whereIn('id', $ids)
                ->sharedLock()->get(['id', 'parent_work_id', 'is_not_accounted']);
            foreach ($parents as $parent) {
                $parentId = (int) $parent->id;
                $parentsById[$parentId] = [
                    'parent_work_id' => $parent->parent_work_id === null ? null : (int) $parent->parent_work_id,
                    'excluded' => (bool) $parent->is_not_accounted,
                ];
                if ($parent->parent_work_id !== null) {
                    $frontier[] = (int) $parent->parent_work_id;
                }
            }
        }

        foreach ($items as $item) {
            $parentId = $item->parent_work_id === null ? null : (int) $item->parent_work_id;
            $seen = [];
            while ($parentId !== null && isset($parentsById[$parentId]) && ! isset($seen[$parentId])) {
                $seen[$parentId] = true;
                if ($parentsById[$parentId]['excluded']) {
                    $excluded[(int) $item->id] = true;
                    break;
                }
                $parentId = $parentsById[$parentId]['parent_work_id'];
            }
        }

        return $excluded;
    }

    private function childResources(Builder $query, Builder $independentQuery, EstimateItem $item, Estimate $estimate, int $organizationId, string $fetchedAt, int $page, int $perPage): array
    {
        $childrenTotal = (clone $query)->count();
        $independentTotal = (clone $independentQuery)->count();
        $total = $childrenTotal + $independentTotal;
        $offset = ($page - 1) * $perPage;
        $withOrganizationUnit = ['measurementUnit' => static fn (BelongsTo $units): BelongsTo => $units->where('organization_id', $organizationId)];
        $children = collect();
        $independentResources = collect();
        if ($offset < $childrenTotal) {
            $childLimit = min($perPage, $childrenTotal - $offset);
            $children = (clone $query)->with($withOrganizationUnit)->orderBy('id')->sharedLock()
                ->offset($offset)->limit($childLimit)->get();
            $remaining = $perPage - $children->count();
            if ($remaining > 0) {
                $independentResources = (clone $independentQuery)->with($withOrganizationUnit)->orderBy('id')->sharedLock()
                    ->limit($remaining)->get();
            }
        } else {
            $independentResources = (clone $independentQuery)->with($withOrganizationUnit)->orderBy('id')->sharedLock()
                ->offset($offset - $childrenTotal)->limit($perPage)->get();
        }
        $shown = $children->count() + $independentResources->count();
        $hasMore = $offset + $shown < $total;
        $resources = [];
        $references = [];
        foreach ($children as $child) {
            $itemType = (string) $child->getRawOriginal('item_type');
            $unit = $child->measurementUnit?->short_name ?? $child->measurementUnit?->name;
            $quantity = $child->getRawOriginal('quantity') === null
                ? null : FinanceDecimal::value((string) $child->getRawOriginal('quantity'), 8);
            $unitPrice = $child->unit_price === null ? null : FinanceDecimal::value((string) $child->unit_price, 4);
            $totalAmount = $child->total_amount === null ? null : FinanceDecimal::value((string) $child->total_amount, 2);
            $fields = [
                'id' => (int) $child->id,
                'estimate_item_id' => (int) $child->id,
                'position_number' => (string) $child->position_number,
                'resource_type' => $itemType,
                'name' => (string) $child->name,
                'quantity' => $quantity,
                'material_unit' => $unit,
                'unit_price' => $unitPrice,
                'total_amount' => $totalAmount,
            ];
            $rowVersion = hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
            $factFields = array_intersect_key($fields, array_fill_keys([
                'position_number', 'resource_type', 'name', 'quantity', 'material_unit', 'unit_price', 'total_amount',
            ], true));
            $reference = [
                'source_type' => 'estimate',
                'entity_type' => 'estimate_item',
                'entity_id' => (int) $child->id,
                'estimate_id' => (int) $estimate->id,
                'estimate_item_id' => (int) $child->id,
                'parent_work_id' => (int) $item->id,
                'composition_scope' => 'resources',
                'project_id' => $estimate->project_id === null ? null : (int) $estimate->project_id,
                'organization_id' => $organizationId,
                'version' => $rowVersion,
                'source_version' => (string) ($child->getRawOriginal('updated_at') ?? $item->getRawOriginal('updated_at')),
                'fetched_at' => $fetchedAt,
                'content_scope' => 'structured',
                'checked_fields' => array_keys($factFields),
                'required_permissions' => ['budget-estimates.view', 'budget-estimates.finance.view'],
                'required_domains' => ['estimates'],
                'navigation' => ['url' => '/estimates/'.$estimate->id.'?position_id='.$item->id],
            ];
            $factRow = ['entity_type' => 'estimate_item', 'entity_id' => (int) $child->id,
                'fields' => $factFields, 'source_ref' => $reference, 'source_version' => $reference['source_version']];
            $factRow['version'] = hash('sha256', json_encode($factRow, JSON_THROW_ON_ERROR));
            $resources[] = $fields + ['version' => $rowVersion];
            $references[] = $reference;
            $references[] = $factRow;
        }
        foreach ($independentResources as $resource) {
            $record = $this->normalizedResourceRecord($resource, $item, $estimate, $organizationId, $fetchedAt);
            $resources[] = $record['item'];
            $references[] = $record['source_ref'];
            $references[] = $record['fact_row'];
        }

        return [
            'status' => 'returned',
            'scope' => 'resources',
            'position_id' => (int) $item->id,
            'items' => $resources,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $hasMore,
            'next_page' => $hasMore ? $page + 1 : null,
            'source_refs' => array_values(array_filter($references, static fn (array $row): bool => isset($row['source_type']))),
            'fact_rows' => array_values(array_filter($references, static fn (array $row): bool => isset($row['fields']))),
        ];
    }

    private function normalizedResourceRecord(EstimateItemResource $resource, EstimateItem $item, Estimate $estimate, int $organizationId, string $fetchedAt): array
    {
        $fields = [
            'id' => (int) $resource->id,
            'estimate_item_id' => (int) $item->id,
            'resource_type' => (string) $resource->resource_type,
            'name' => (string) $resource->name,
            'quantity_per_unit' => FinanceDecimal::value((string) $resource->quantity_per_unit, 4),
            'total_quantity' => FinanceDecimal::value((string) $resource->total_quantity, 4),
            'material_unit' => $resource->measurementUnit?->short_name ?? $resource->measurementUnit?->name,
            'unit_price' => $resource->unit_price === null ? null : FinanceDecimal::value((string) $resource->unit_price, 2),
            'total_amount' => $resource->total_amount === null ? null : FinanceDecimal::value((string) $resource->total_amount, 2),
        ];
        $rowVersion = hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
        $reference = [
            'source_type' => 'estimate',
            'entity_type' => 'estimate_item_resource',
            'entity_id' => (int) $resource->id,
            'estimate_id' => (int) $estimate->id,
            'estimate_item_id' => (int) $item->id,
            'composition_scope' => 'resources',
            'finance_representation' => (string) $resource->finance_representation,
            'project_id' => $estimate->project_id === null ? null : (int) $estimate->project_id,
            'organization_id' => $organizationId,
            'version' => $rowVersion,
            'source_version' => (string) ($resource->getRawOriginal('updated_at') ?? $item->getRawOriginal('updated_at')),
            'fetched_at' => $fetchedAt,
            'content_scope' => 'structured',
            'checked_fields' => array_keys($fields),
            'required_permissions' => ['budget-estimates.view', 'budget-estimates.finance.view'],
            'required_domains' => ['estimates'],
            'navigation' => ['url' => '/estimates/'.$estimate->id.'?position_id='.$item->id],
        ];
        $factRow = ['entity_type' => 'estimate_item_resource', 'entity_id' => (int) $resource->id,
            'fields' => $fields, 'source_ref' => $reference, 'source_version' => $reference['source_version']];
        $factRow['version'] = hash('sha256', json_encode($factRow, JSON_THROW_ON_ERROR));

        return ['item' => $fields + ['version' => $rowVersion], 'source_ref' => $reference, 'fact_row' => $factRow];
    }

    private function independentResourceQuery(EstimateItem $item, Estimate $estimate): Builder
    {
        return EstimateItemResource::query()->where('estimate_item_id', $item->id)
            ->where('finance_representation', 'independent')->whereNull('represented_by_item_id')
            ->whereHas('item', static fn (Builder $parent): Builder => $parent->where('estimate_id', $estimate->id));
    }

    private function resources(EstimateItem $item, Estimate $estimate, int $organizationId, string $fetchedAt, int $page, int $perPage): array
    {
        $children = EstimateItem::query()->where('estimate_id', $estimate->id)->where('parent_work_id', $item->id);
        if ((clone $children)->exists()) {
            return $this->childResources($children, $this->independentResourceQuery($item, $estimate), $item, $estimate,
                $organizationId, $fetchedAt, $page, $perPage);
        }

        $query = EstimateItemResource::query()->where('estimate_item_id', $item->id)
            ->where('finance_representation', 'independent')
            ->whereNull('represented_by_item_id')
            ->whereHas('item', static fn (Builder $parent): Builder => $parent->where('estimate_id', $estimate->id));
        $total = (clone $query)->count();
        if ($total === 0 && EstimateItemResource::query()->where('estimate_item_id', $item->id)
            ->where('finance_representation', 'unreviewed')
            ->whereHas('item', static fn (Builder $parent): Builder => $parent->where('estimate_id', $estimate->id))
            ->exists()) {
            return [
                'status' => 'unknown',
                'scope' => 'resources',
                'position_id' => (int) $item->id,
                'items' => [],
                'total' => null,
                'page' => $page,
                'per_page' => $perPage,
                'has_more' => null,
                'next_page' => null,
                'source_refs' => [],
                'fact_rows' => [],
            ];
        }
        $rows = $query->with(['measurementUnit' => static fn (BelongsTo $units): BelongsTo => $units->where('organization_id', $organizationId)])
            ->orderBy('id')->sharedLock()
            ->offset(($page - 1) * $perPage)->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $resources = [];
        $references = [];
        foreach ($rows->take($perPage) as $resource) {
            $record = $this->normalizedResourceRecord($resource, $item, $estimate, $organizationId, $fetchedAt);
            $resources[] = $record['item'];
            $references[] = $record['source_ref'];
            $references[] = $record['fact_row'];
        }

        return [
            'status' => 'returned',
            'scope' => 'resources',
            'position_id' => (int) $item->id,
            'items' => $resources,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $hasMore,
            'next_page' => $hasMore ? $page + 1 : null,
            'source_refs' => array_values(array_filter($references, static fn (array $row): bool => isset($row['source_type']))),
            'fact_rows' => array_values(array_filter($references, static fn (array $row): bool => isset($row['fields']))),
        ];
    }
}
