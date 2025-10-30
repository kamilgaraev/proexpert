<?php

namespace App\Repositories;

use App\Models\ContractStateEvent;
use App\Repositories\Interfaces\ContractStateEventRepositoryInterface;
use App\Enums\Contract\ContractStateEventTypeEnum;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ContractStateEventRepository extends BaseRepository implements ContractStateEventRepositoryInterface
{
    public function __construct()
    {
        parent::__construct(ContractStateEvent::class);
    }

    /**
     * Найти все события для конкретного договора
     */
    public function findByContract(int $contractId, array $relations = []): Collection
    {
        $cacheKey = "contract_state_events:contract:{$contractId}";
        
        return Cache::remember($cacheKey, 300, function () use ($contractId, $relations) {
            return $this->model
                ->forContract($contractId)
                ->with($relations)
                ->orderBy('created_at', 'asc')
                ->get();
        });
    }

    /**
     * Найти активные события (не аннулированные) для договора
     */
    public function findActiveEvents(int $contractId, array $relations = []): Collection
    {
        $cacheKey = "contract_state_events:active:contract:{$contractId}";
        
        return Cache::remember($cacheKey, 300, function () use ($contractId, $relations) {
            return $this->model
                ->forContract($contractId)
                ->active()
                ->with($relations)
                ->orderBy('effective_from', 'asc')
                ->orderBy('created_at', 'asc')
                ->get();
        });
    }

    /**
     * Найти события, которые аннулируют указанное событие
     */
    public function findSupersedingEvents(int $eventId): Collection
    {
        return $this->model
            ->where('supersedes_event_id', $eventId)
            ->get();
    }

    /**
     * Найти активные события до определенной даты
     */
    public function findActiveEventsAsOfDate(int $contractId, Carbon $date, array $relations = []): Collection
    {
        return $this->model
            ->forContract($contractId)
            ->active()
            ->asOfDate($date)
            ->with($relations)
            ->orderBy('effective_from', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Найти события по типу
     */
    public function findByType(int $contractId, ContractStateEventTypeEnum $type, array $relations = []): Collection
    {
        return $this->model
            ->forContract($contractId)
            ->ofType($type)
            ->with($relations)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Получить timeline событий для договора
     */
    public function getTimeline(int $contractId, ?Carbon $asOfDate = null): Collection
    {
        $query = $this->model->forContract($contractId);

        if ($asOfDate) {
            $query->where('created_at', '<=', $asOfDate)
                  ->where(function ($q) use ($asOfDate) {
                      $q->whereNull('effective_from')
                        ->orWhere('effective_from', '<=', $asOfDate);
                  });
        }

        return $query
            ->with(['specification', 'createdBy', 'supersedesEvent'])
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Получить последнее событие определенного типа
     */
    public function getLatestEventByType(int $contractId, ContractStateEventTypeEnum $type): ?ContractStateEvent
    {
        return $this->model
            ->forContract($contractId)
            ->ofType($type)
            ->latest('created_at')
            ->first();
    }

    /**
     * Создать событие
     */
    public function createEvent(array $data): ContractStateEvent
    {
        $event = $this->create($data);
        
        // Очистить кеш событий для этого договора
        $this->clearContractCache($data['contract_id']);
        
        return $event;
    }

    /**
     * Очистить кеш событий для договора
     */
    protected function clearContractCache(int $contractId): void
    {
        Cache::forget("contract_state_events:contract:{$contractId}");
        Cache::forget("contract_state_events:active:contract:{$contractId}");
    }

    /**
     * Переопределяем метод update для очистки кеша
     */
    public function update(int $modelId, array $payload): bool
    {
        $result = parent::update($modelId, $payload);
        
        if ($result) {
            $event = $this->find($modelId);
            if ($event) {
                $this->clearContractCache($event->contract_id);
            }
        }
        
        return $result;
    }

    /**
     * Переопределяем метод delete для очистки кеша
     */
    public function delete(int $modelId): bool
    {
        $event = $this->find($modelId);
        $contractId = $event?->contract_id;
        
        $result = parent::delete($modelId);
        
        if ($result && $contractId) {
            $this->clearContractCache($contractId);
        }
        
        return $result;
    }
}

