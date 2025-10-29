<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\Estimate;
use App\Repositories\EstimateRepository;
use App\Repositories\EstimateSectionRepository;
use App\Repositories\EstimateItemRepository;
use Illuminate\Support\Facades\DB;

class EstimateService
{
    public function __construct(
        protected EstimateRepository $repository,
        protected EstimateSectionRepository $sectionRepository,
        protected EstimateItemRepository $itemRepository,
        protected EstimateCalculationService $calculationService
    ) {}

    public function create(array $data): Estimate
    {
        // Retry mechanism for race condition protection
        $maxAttempts = 3;
        $attempt = 0;
        
        while ($attempt < $maxAttempts) {
            try {
                return DB::transaction(function () use ($data) {
                    if (!isset($data['number'])) {
                        $data['number'] = $this->generateNumber($data['organization_id']);
                    }
                    
                    if (!isset($data['estimate_date'])) {
                        $data['estimate_date'] = now();
                    }
                    
                    $estimate = $this->repository->create($data);
                    
                    return $estimate;
                });
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $attempt++;
                
                // If this was the last attempt, rethrow the exception
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                
                // Small random delay to avoid thundering herd
                usleep(rand(10000, 50000)); // 10-50ms
                
                // Clear the number to force regeneration on next attempt
                unset($data['number']);
            }
        }
        
        throw new \RuntimeException('Failed to create estimate after ' . $maxAttempts . ' attempts');
    }

    public function update(Estimate $estimate, array $data): Estimate
    {
        return DB::transaction(function () use ($estimate, $data) {
            $this->repository->update($estimate, $data);
            
            if (isset($data['overhead_rate']) || isset($data['profit_rate']) || isset($data['vat_rate'])) {
                $this->calculationService->recalculateAll($estimate);
            }
            
            return $estimate->fresh();
        });
    }

    public function delete(Estimate $estimate): bool
    {
        if ($estimate->isApproved()) {
            throw new \Exception('Нельзя удалить утвержденную смету');
        }
        
        return $this->repository->delete($estimate);
    }

    public function duplicate(Estimate $estimate, ?string $newNumber = null, ?string $newName = null): Estimate
    {
        // Retry mechanism for race condition protection
        $maxAttempts = 3;
        $attempt = 0;
        
        while ($attempt < $maxAttempts) {
            try {
                return DB::transaction(function () use ($estimate, $newNumber, $newName) {
                    $overrides = [
                        'number' => $newNumber ?? $this->generateNumber($estimate->organization_id),
                        'name' => $newName ?? $estimate->name . ' (копия)',
                        'status' => 'draft',
                        'version' => 1,
                        'parent_estimate_id' => null,
                        'approved_at' => null,
                        'approved_by_user_id' => null,
                    ];
                    
                    $newEstimate = $this->repository->duplicate($estimate, $overrides);
            
            $sections = $this->sectionRepository->getByEstimate($estimate->id);
            $sectionMapping = [];
            
            foreach ($sections as $section) {
                $newSection = $this->sectionRepository->create([
                    'estimate_id' => $newEstimate->id,
                    'parent_section_id' => isset($sectionMapping[$section->parent_section_id]) 
                        ? $sectionMapping[$section->parent_section_id] 
                        : null,
                    'section_number' => $section->section_number,
                    'name' => $section->name,
                    'description' => $section->description,
                    'sort_order' => $section->sort_order,
                    'is_summary' => $section->is_summary,
                ]);
                
                $sectionMapping[$section->id] = $newSection->id;
            }
            
            $items = $this->itemRepository->getAllByEstimate($estimate->id);
            foreach ($items as $item) {
                $this->itemRepository->create([
                    'estimate_id' => $newEstimate->id,
                    'estimate_section_id' => isset($sectionMapping[$item->estimate_section_id]) 
                        ? $sectionMapping[$item->estimate_section_id] 
                        : null,
                    'position_number' => $item->position_number,
                    'name' => $item->name,
                    'description' => $item->description,
                    'work_type_id' => $item->work_type_id,
                    'measurement_unit_id' => $item->measurement_unit_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'direct_costs' => $item->direct_costs,
                    'overhead_amount' => $item->overhead_amount,
                    'profit_amount' => $item->profit_amount,
                    'total_amount' => $item->total_amount,
                    'justification' => $item->justification,
                    'is_manual' => $item->is_manual,
                    'metadata' => $item->metadata,
                ]);
            }
            
            return $newEstimate;
                });
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $attempt++;
                
                // If this was the last attempt, rethrow the exception
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                
                // Small random delay to avoid thundering herd
                usleep(rand(10000, 50000)); // 10-50ms
                
                // Clear newNumber to force regeneration on next attempt
                $newNumber = null;
            }
        }
        
        throw new \RuntimeException('Failed to duplicate estimate after ' . $maxAttempts . ' attempts');
    }

    public function getById(int $id): ?Estimate
    {
        return $this->repository->find($id);
    }

    public function getByProject(int $projectId)
    {
        return $this->repository->getByProject($projectId);
    }

    public function getByContract(int $contractId)
    {
        return $this->repository->getByContract($contractId);
    }

    protected function generateNumber(int $organizationId): string
    {
        $year = now()->year;
        $prefix = "СМ-{$year}-";
        
        $driver = config('database.default');
        $connection = config("database.connections.{$driver}.driver");
        
        if ($connection === 'pgsql') {
            $orderBy = 'CAST(SUBSTRING(number, ' . (strlen($prefix) + 1) . ') AS INTEGER) DESC';
        } else {
            $orderBy = 'CAST(SUBSTRING(number, ' . (strlen($prefix) + 1) . ') AS UNSIGNED) DESC';
        }
        
        // Use pessimistic locking to prevent race conditions
        // lockForUpdate() will lock the row until the transaction commits
        $lastEstimate = Estimate::where('organization_id', $organizationId)
            ->where('number', 'like', $prefix . '%')
            ->orderByRaw($orderBy)
            ->lockForUpdate()
            ->first();
        
        if ($lastEstimate) {
            $lastNumber = (int) substr($lastEstimate->number, strlen($prefix));
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }
        
        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}

