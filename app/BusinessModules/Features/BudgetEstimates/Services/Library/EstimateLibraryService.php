<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Library;

use App\BusinessModules\Features\BudgetEstimates\Policies\EstimatePolicy;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateLibrary;
use App\Models\EstimateLibraryItem;
use App\Models\EstimateLibraryItemPosition;
use App\Models\EstimateLibraryUsage;
use App\Models\EstimateSection;
use App\Models\User;
use App\Repositories\EstimateItemRepository;
use App\Repositories\EstimateLibraryRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EstimateLibraryService
{
    public function __construct(
        protected EstimateLibraryRepository $repository,
        private readonly AuthorizationService $authorization,
    ) {}

    public function getLibraries(User $actor, array $filters = [], bool $accessible = true): LengthAwarePaginator
    {
        $organizationId = $this->authorizeRead($actor);

        return $accessible
            ? $this->repository->getAccessible($organizationId, $filters)
            : $this->repository->getByOrganization($organizationId, $filters);
    }

    public function getLibrary(int $id, User $actor): EstimateLibrary
    {
        return $this->repository->findCurrentLibrary($id, $this->authorizeRead($actor))->load('items.positions');
    }

    public function getLibraryItems(int $libraryId, User $actor): Collection
    {
        return $this->repository->getItemsByAccessibleLibrary($libraryId, $this->authorizeRead($actor));
    }

    public function getLibraryItem(int $itemId, User $actor): EstimateLibraryItem
    {
        return $this->repository->findCurrentItem($itemId, $this->authorizeRead($actor))->load('positions.normativeRate');
    }

    public function createLibrary(User $actor, array $data): EstimateLibrary
    {
        $organizationId = $this->authorizeManage($actor);
        $library = EstimateLibrary::create([
            'organization_id' => $organizationId,
            'created_by_user_id' => $actor->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? null,
            'access_level' => $data['access_level'] ?? 'private',
            'tags' => $data['tags'] ?? [],
            'is_active' => true,
        ]);

        $this->repository->clearCache();

        return $library;
    }

    public function updateLibrary(int $libraryId, User $actor, array $data): EstimateLibrary
    {
        return DB::transaction(function () use ($libraryId, $actor, $data) {
            $library = $this->repository->findCurrentLibrary($libraryId, $this->authorizeManage($actor), true, true);
            $library->update([
                'name' => $data['name'] ?? $library->name,
                'description' => $data['description'] ?? $library->description,
                'category' => $data['category'] ?? $library->category,
                'access_level' => $data['access_level'] ?? $library->access_level,
                'tags' => $data['tags'] ?? $library->tags,
                'is_active' => $data['is_active'] ?? $library->is_active,
            ]);

            $this->repository->clearCache($library->id);

            return $library->fresh();
        });
    }

    public function deleteLibrary(int $libraryId, User $actor): bool
    {
        return DB::transaction(function () use ($libraryId, $actor) {
            $library = $this->repository->findCurrentLibrary($libraryId, $this->authorizeManage($actor), true, true);
            $deleted = $library->delete();
            $this->repository->clearCache($library->id);

            return (bool) $deleted;
        });
    }

    public function createLibraryItem(int $libraryId, User $actor, array $data): EstimateLibraryItem
    {
        return DB::transaction(function () use ($libraryId, $actor, $data) {
            $library = $this->repository->findCurrentLibrary($libraryId, $this->authorizeManage($actor), true, true);
            $item = EstimateLibraryItem::create([
                'library_id' => $library->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'parameters' => $data['parameters'] ?? [],
                'calculation_rules' => $data['calculation_rules'] ?? null,
                'positions_count' => 0,
            ]);

            if (! empty($data['positions'])) {
                foreach ($data['positions'] as $index => $positionData) {
                    $this->addPositionToItem($item, $positionData, $index);
                }

                $item->positions_count = count($data['positions']);
                $item->save();
            }

            $this->repository->clearCache($library->id);

            return $item->fresh(['positions']);
        });
    }

    private function addPositionToItem(
        EstimateLibraryItem $item,
        array $positionData,
        int $sortOrder,
    ): EstimateLibraryItemPosition {
        return EstimateLibraryItemPosition::create([
            'library_item_id' => $item->id,
            'normative_rate_id' => $positionData['normative_rate_id'] ?? null,
            'normative_rate_code' => $positionData['normative_rate_code'] ?? null,
            'name' => $positionData['name'],
            'description' => $positionData['description'] ?? null,
            'measurement_unit' => $positionData['measurement_unit'] ?? null,
            'sort_order' => $sortOrder,
            'quantity_formula' => $positionData['quantity_formula'] ?? null,
            'default_quantity' => $positionData['default_quantity'] ?? 1,
            'coefficient' => $positionData['coefficient'] ?? 1,
            'parameters_mapping' => $positionData['parameters_mapping'] ?? [],
        ]);
    }

    public function applyLibraryItemToEstimate(
        EstimateLibraryItem $libraryItem,
        Estimate $estimate,
        User $actor,
        array $parameters = [],
        ?int $sectionId = null
    ): array {
        return DB::transaction(function () use ($libraryItem, $estimate, $actor, $parameters, $sectionId) {
            $organizationId = $this->authorizeRead($actor);
            $libraryItem = $this->repository->findCurrentItem($libraryItem->id, $organizationId, false, true);
            $estimate = Estimate::query()->useWritePdo()->whereKey($estimate->id)
                ->where('organization_id', $organizationId)->lockForUpdate()->firstOrFail();
            $permission = $estimate->isApproved() ? 'budget-estimates.edit_approved' : 'budget-estimates.edit';
            if (! $this->can($actor, $permission, $organizationId) || ! app(EstimatePolicy::class)->update($actor, $estimate)) {
                throw new AuthorizationException(trans_message('permissions.access_denied'));
            }
            if ($sectionId !== null) {
                EstimateSection::query()->useWritePdo()->whereKey($sectionId)->where('estimate_id', $estimate->id)->firstOrFail();
            }
            if (! $libraryItem->validateParameters($parameters)) {
                throw new \InvalidArgumentException('Отсутствуют обязательные параметры для применения типового решения');
            }
            (new \App\BusinessModules\Features\BudgetEstimates\Services\EstimateSourceVatCalculator)->assertStructureMutable($estimate);
            $addedItems = [];
            $positions = $libraryItem->positions()->ordered()->get();

            foreach ($positions as $position) {
                $quantity = $position->calculateQuantity($parameters);

                $item = new EstimateItem([
                    'estimate_id' => $estimate->id,
                    'estimate_section_id' => $sectionId,
                    'position_number' => app(EstimateItemRepository::class)->getNextPositionNumber($estimate->id),
                    'normative_rate_id' => $position->normative_rate_id,
                    'normative_rate_code' => $position->normative_rate_code,
                    'name' => $position->name,
                    'description' => $position->description,
                    'measurement_unit_id' => $position->measurement_unit ?? null,
                    'quantity' => $quantity,
                    'is_manual' => false,
                    'metadata' => [
                        'from_library' => true,
                        'library_item_id' => $libraryItem->id,
                        'applied_parameters' => $parameters,
                    ],
                ]);

                $item->save();
                $addedItems[] = $item;
            }

            EstimateLibraryUsage::create([
                'library_item_id' => $libraryItem->id,
                'estimate_id' => $estimate->id,
                'user_id' => $actor->id,
                'applied_parameters' => $parameters,
                'positions_added' => count($addedItems),
                'used_at' => now(),
            ]);

            $libraryItem->incrementUsage();

            return $addedItems;
        });
    }

    public function getUsageStatistics(int $itemId, User $actor, ?int $days = 30): array
    {
        $item = $this->repository->findCurrentItem($itemId, $this->authorizeRead($actor), true);
        $query = EstimateLibraryUsage::where('library_item_id', $item->id);

        if ($days) {
            $query->where('used_at', '>=', now()->subDays($days));
        }

        $usages = $query->with(['estimate', 'user'])->get();

        return [
            'total_uses' => $usages->count(),
            'unique_estimates' => $usages->pluck('estimate_id')->unique()->count(),
            'unique_users' => $usages->pluck('user_id')->unique()->count(),
            'total_positions_added' => $usages->sum('positions_added'),
            'last_used_at' => $usages->max('used_at'),
            'most_common_parameters' => $this->analyzeMostCommonParameters($usages),
        ];
    }

    public function shareLibrary(int $libraryId, User $actor, string $accessLevel = 'public'): EstimateLibrary
    {
        if (! in_array($accessLevel, ['private', 'organization', 'public'])) {
            throw new \InvalidArgumentException('Недопустимый уровень доступа');
        }

        return DB::transaction(function () use ($libraryId, $actor, $accessLevel) {
            $library = $this->repository->findCurrentLibrary($libraryId, $this->authorizeManage($actor), true, true);
            $library->update(['access_level' => $accessLevel]);
            $this->repository->clearCache($library->id);

            return $library->fresh();
        });
    }

    public function duplicateLibrary(
        int $libraryId,
        User $actor,
        ?string $newName = null
    ): EstimateLibrary {
        return DB::transaction(function () use ($libraryId, $actor, $newName) {
            $organizationId = $this->authorizeManage($actor);
            $this->authorizeRead($actor);
            $library = $this->repository->findCurrentLibrary($libraryId, $organizationId, false, true);
            $newLibrary = EstimateLibrary::create([
                'organization_id' => $organizationId,
                'created_by_user_id' => $actor->id,
                'name' => $newName ?? ($library->name.' (копия)'),
                'description' => $library->description,
                'category' => $library->category,
                'access_level' => 'private',
                'tags' => $library->tags,
                'is_active' => true,
            ]);

            $items = $library->items()->with('positions')->get();

            foreach ($items as $item) {
                $newItem = EstimateLibraryItem::create([
                    'library_id' => $newLibrary->id,
                    'name' => $item->name,
                    'description' => $item->description,
                    'parameters' => $item->parameters,
                    'calculation_rules' => $item->calculation_rules,
                    'positions_count' => $item->positions_count,
                ]);

                foreach ($item->positions as $position) {
                    EstimateLibraryItemPosition::create([
                        'library_item_id' => $newItem->id,
                        'normative_rate_id' => $position->normative_rate_id,
                        'normative_rate_code' => $position->normative_rate_code,
                        'name' => $position->name,
                        'description' => $position->description,
                        'measurement_unit' => $position->measurement_unit,
                        'sort_order' => $position->sort_order,
                        'quantity_formula' => $position->quantity_formula,
                        'default_quantity' => $position->default_quantity,
                        'coefficient' => $position->coefficient,
                        'parameters_mapping' => $position->parameters_mapping,
                    ]);
                }
            }

            $this->repository->clearCache();

            return $newLibrary->fresh(['items.positions']);
        });
    }

    private function authorizeRead(User $actor): int
    {
        $organizationId = $this->activeOrganizationId($actor);
        if (! $this->can($actor, 'budget-estimates.view', $organizationId)
            && ! $this->can($actor, 'budget-estimates.view_all', $organizationId)) {
            throw new AuthorizationException(trans_message('permissions.access_denied'));
        }

        return $organizationId;
    }

    private function authorizeManage(User $actor): int
    {
        $organizationId = $this->activeOrganizationId($actor);
        if (! $this->can($actor, 'budget-estimates.templates.manage', $organizationId)) {
            throw new AuthorizationException(trans_message('permissions.access_denied'));
        }

        return $organizationId;
    }

    private function activeOrganizationId(User $actor): int
    {
        $organizationId = (int) $actor->current_organization_id;
        if ($organizationId <= 0
            || ! User::query()->useWritePdo()->whereKey($actor->id)->where('is_active', true)->exists()
            || ! $actor->organizations()->useWritePdo()->where('organizations.id', $organizationId)
                ->where('organizations.is_active', true)->wherePivot('is_active', true)->exists()) {
            throw new AuthorizationException(trans_message('permissions.access_denied'));
        }

        return $organizationId;
    }

    private function can(User $actor, string $permission, int $organizationId): bool
    {
        return $this->authorization->canCurrent($actor, $permission, [
            'context_type' => 'organization', 'organization_id' => $organizationId,
        ]);
    }

    protected function analyzeMostCommonParameters(Collection $usages): array
    {
        $parametersCount = [];

        foreach ($usages as $usage) {
            $params = $usage->applied_parameters ?? [];

            foreach ($params as $key => $value) {
                $paramKey = $key.'='.$value;

                if (! isset($parametersCount[$paramKey])) {
                    $parametersCount[$paramKey] = 0;
                }

                $parametersCount[$paramKey]++;
            }
        }

        arsort($parametersCount);

        return array_slice($parametersCount, 0, 10, true);
    }
}
