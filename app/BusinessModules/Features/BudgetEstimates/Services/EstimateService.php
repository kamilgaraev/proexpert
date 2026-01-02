<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\Estimate;
use App\Repositories\EstimateRepository;
use App\Repositories\EstimateSectionRepository;
use App\Repositories\EstimateItemRepository;
use App\BusinessModules\Features\BudgetEstimates\BudgetEstimatesModule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class EstimateService
{
    public function __construct(
        protected EstimateRepository $repository,
        protected EstimateSectionRepository $sectionRepository,
        protected EstimateItemRepository $itemRepository,
        protected EstimateCalculationService $calculationService,
        protected BudgetEstimatesModule $module
    ) {}

    public function create(array $data): Estimate
    {
        // Retry mechanism for race condition protection
        $maxAttempts = 3;
        $attempt = 0;
        
        // Получаем настройки модуля для значений по умолчанию
        $settings = $this->module->getSettings($data['organization_id']);
        $defaults = $settings['estimate_settings'] ?? [];

        // Обработка overhead_rate (Накладные расходы)
        // Если значение не передано или null, используем настройку по умолчанию
        // Если передано 0, используем 0
        if (!array_key_exists('overhead_rate', $data) || is_null($data['overhead_rate'])) {
            $data['overhead_rate'] = $defaults['default_overhead_rate'] ?? 15;
        }

        // Обработка profit_rate (Сметная прибыль)
        // Если значение не передано или null, используем настройку по умолчанию
        // Если передано 0, используем 0
        if (!array_key_exists('profit_rate', $data) || is_null($data['profit_rate'])) {
            $data['profit_rate'] = $defaults['default_profit_rate'] ?? 12;
        }

        // Обработка vat_rate (НДС)
        if (!array_key_exists('vat_rate', $data) || is_null($data['vat_rate'])) {
            $data['vat_rate'] = $defaults['default_vat_rate'] ?? 20;
        }
        
        while ($attempt < $maxAttempts) {
            try {
                // Generate number BEFORE main transaction to avoid race conditions
                if (!isset($data['number'])) {
                    $data['number'] = $this->generateNumber($data['organization_id']);
                }
                
                return DB::transaction(function () use ($data) {
                    if (!isset($data['estimate_date'])) {
                        $data['estimate_date'] = now();
                    }
                    
                    $estimate = $this->repository->create($data);
                    
                    Log::info('estimate.created', [
                        'estimate_id' => $estimate->id,
                        'number' => $estimate->number,
                        'organization_id' => $estimate->organization_id,
                        'project_id' => $estimate->project_id,
                        'user_id' => Auth::id(),
                    ]);
                    
                    return $estimate;
                });
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $attempt++;
                
                Log::warning('estimate.create.duplicate_number', [
                    'attempt' => $attempt,
                    'number' => $data['number'] ?? null,
                    'organization_id' => $data['organization_id'],
                ]);
                
                // If this was the last attempt, rethrow the exception
                if ($attempt >= $maxAttempts) {
                    Log::error('estimate.create.failed', [
                        'max_attempts_reached' => $maxAttempts,
                        'last_number' => $data['number'] ?? null,
                    ]);
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
            
            Log::info('estimate.updated', [
                'estimate_id' => $estimate->id,
                'changed_fields' => array_keys($data),
                'user_id' => Auth::id(),
            ]);
            
            return $estimate->fresh();
        });
    }

    public function delete(Estimate $estimate): bool
    {
        if ($estimate->isApproved()) {
            throw new \Exception('Нельзя удалить утвержденную смету');
        }
        
        Log::warning('estimate.deleting', [
            'estimate_id' => $estimate->id,
            'number' => $estimate->number,
            'organization_id' => $estimate->organization_id,
            'status' => $estimate->status,
            'total_amount' => $estimate->total_amount,
            'user_id' => Auth::id(),
        ]);
        
        $result = $this->repository->delete($estimate);
        
        if ($result) {
            Log::info('estimate.deleted', [
                'estimate_id' => $estimate->id,
                'number' => $estimate->number,
            ]);
        }
        
        return $result;
    }

    public function duplicate(Estimate $estimate, ?string $newNumber = null, ?string $newName = null): Estimate
    {
        // Retry mechanism for race condition protection
        $maxAttempts = 3;
        $attempt = 0;
        $providedNumber = $newNumber; // Save original provided number
        
        while ($attempt < $maxAttempts) {
            try {
                // Generate number BEFORE transaction if not provided
                if (!$newNumber) {
                    $newNumber = $this->generateNumber($estimate->organization_id);
                }
                
                return DB::transaction(function () use ($estimate, $newNumber, $newName) {
                    $overrides = [
                        'number' => $newNumber,
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
                
                Log::warning('estimate.duplicate.duplicate_number', [
                    'attempt' => $attempt,
                    'number' => $newNumber,
                    'source_estimate_id' => $estimate->id,
                ]);
                
                // If this was the last attempt, rethrow the exception
                if ($attempt >= $maxAttempts) {
                    Log::error('estimate.duplicate.failed', [
                        'max_attempts_reached' => $maxAttempts,
                        'last_number' => $newNumber,
                    ]);
                    throw $e;
                }
                
                // Small random delay to avoid thundering herd
                usleep(rand(10000, 50000)); // 10-50ms
                
                // Clear newNumber to force regeneration on next attempt
                // Only regenerate if number was not explicitly provided
                if (!$providedNumber) {
                    $newNumber = null;
                }
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
        
        // Используем атомарный инкремент в отдельной таблице
        // Это работает корректно даже с Laravel Octane и connection pooling
        $newNumber = DB::transaction(function () use ($organizationId, $year) {
            // Используем raw SQL для атомарного increment с INSERT ON CONFLICT
            $result = DB::selectOne("
                INSERT INTO estimate_number_counters (organization_id, year, last_number, created_at, updated_at)
                VALUES (?, ?, 1, NOW(), NOW())
                ON CONFLICT (organization_id, year) 
                DO UPDATE SET 
                    last_number = estimate_number_counters.last_number + 1,
                    updated_at = NOW()
                RETURNING last_number
            ", [$organizationId, $year]);
            
            return $result->last_number;
        });
        
        $number = $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
        
        // Логирование для отладки
        Log::debug('estimate.number_generated', [
            'organization_id' => $organizationId,
            'year' => $year,
            'generated_number' => $number,
            'counter_value' => $newNumber,
        ]);
        
        return $number;
    }
}

