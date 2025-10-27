<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Library;

use App\Models\Estimate;
use App\Models\EstimateLibrary;
use App\Models\EstimateLibraryItem;
use App\Models\EstimateLibraryItemPosition;
use App\Models\EstimateLibraryUsage;
use App\Models\EstimateItem;
use App\Repositories\EstimateLibraryRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;

class EstimateLibraryService
{
    public function __construct(
        protected EstimateLibraryRepository $repository
    ) {}

    public function createLibrary(int $organizationId, int $userId, array $data): EstimateLibrary
    {
        $library = EstimateLibrary::create([
            'organization_id' => $organizationId,
            'created_by_user_id' => $userId,
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

    public function updateLibrary(EstimateLibrary $library, array $data): EstimateLibrary
    {
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
    }

    public function deleteLibrary(EstimateLibrary $library): bool
    {
        $this->repository->clearCache($library->id);
        
        return $library->delete();
    }

    public function createLibraryItem(EstimateLibrary $library, array $data): EstimateLibraryItem
    {
        return DB::transaction(function () use ($library, $data) {
            $item = EstimateLibraryItem::create([
                'library_id' => $library->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'parameters' => $data['parameters'] ?? [],
                'calculation_rules' => $data['calculation_rules'] ?? null,
                'positions_count' => 0,
            ]);

            if (!empty($data['positions'])) {
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

    public function addPositionToItem(
        EstimateLibraryItem $item,
        array $positionData,
        ?int $sortOrder = null
    ): EstimateLibraryItemPosition {
        if ($sortOrder === null) {
            $sortOrder = $item->positions()->max('sort_order') + 1;
        }

        $position = EstimateLibraryItemPosition::create([
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

        $item->increment('positions_count');

        return $position;
    }

    public function applyLibraryItemToEstimate(
        EstimateLibraryItem $libraryItem,
        Estimate $estimate,
        int $userId,
        array $parameters = [],
        ?int $sectionId = null
    ): array {
        if (!$libraryItem->validateParameters($parameters)) {
            throw new \InvalidArgumentException('Отсутствуют обязательные параметры для применения типового решения');
        }

        return DB::transaction(function () use ($libraryItem, $estimate, $userId, $parameters, $sectionId) {
            $addedItems = [];
            $positions = $libraryItem->positions()->ordered()->get();

            foreach ($positions as $position) {
                $quantity = $position->calculateQuantity($parameters);

                $item = new EstimateItem([
                    'estimate_id' => $estimate->id,
                    'estimate_section_id' => $sectionId,
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
                'user_id' => $userId,
                'applied_parameters' => $parameters,
                'positions_added' => count($addedItems),
                'used_at' => now(),
            ]);

            $libraryItem->incrementUsage();

            return $addedItems;
        });
    }

    public function getUsageStatistics(EstimateLibraryItem $item, ?int $days = 30): array
    {
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

    public function shareLibrary(EstimateLibrary $library, string $accessLevel = 'public'): EstimateLibrary
    {
        if (!in_array($accessLevel, ['private', 'organization', 'public'])) {
            throw new \InvalidArgumentException('Недопустимый уровень доступа');
        }

        $library->update(['access_level' => $accessLevel]);
        $this->repository->clearCache($library->id);

        return $library->fresh();
    }

    public function duplicateLibrary(
        EstimateLibrary $library,
        int $organizationId,
        int $userId,
        ?string $newName = null
    ): EstimateLibrary {
        return DB::transaction(function () use ($library, $organizationId, $userId, $newName) {
            $newLibrary = EstimateLibrary::create([
                'organization_id' => $organizationId,
                'created_by_user_id' => $userId,
                'name' => $newName ?? ($library->name . ' (копия)'),
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

            return $newLibrary->fresh(['items.positions']);
        });
    }

    protected function analyzeMostCommonParameters(Collection $usages): array
    {
        $parametersCount = [];

        foreach ($usages as $usage) {
            $params = $usage->applied_parameters ?? [];
            
            foreach ($params as $key => $value) {
                $paramKey = $key . '=' . $value;
                
                if (!isset($parametersCount[$paramKey])) {
                    $parametersCount[$paramKey] = 0;
                }
                
                $parametersCount[$paramKey]++;
            }
        }

        arsort($parametersCount);

        return array_slice($parametersCount, 0, 10, true);
    }
}

