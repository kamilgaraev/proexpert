<?php

namespace App\Repositories\Interfaces;

use App\Models\ContractStateEvent;
use App\Enums\Contract\ContractStateEventTypeEnum;
use Illuminate\Support\Collection;
use Carbon\Carbon;

interface ContractStateEventRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Найти все события для конкретного договора
     */
    public function findByContract(int $contractId, array $relations = []): Collection;

    /**
     * Найти активные события (не аннулированные) для договора
     */
    public function findActiveEvents(int $contractId, array $relations = []): Collection;

    /**
     * Найти события, которые аннулируют указанное событие
     */
    public function findSupersedingEvents(int $eventId): Collection;

    /**
     * Найти активные события до определенной даты
     */
    public function findActiveEventsAsOfDate(int $contractId, Carbon $date, array $relations = []): Collection;

    /**
     * Найти события по типу
     */
    public function findByType(int $contractId, ContractStateEventTypeEnum $type, array $relations = []): Collection;

    /**
     * Получить timeline событий для договора
     */
    public function getTimeline(int $contractId, ?Carbon $asOfDate = null): Collection;

    /**
     * Получить последнее событие определенного типа
     */
    public function getLatestEventByType(int $contractId, ContractStateEventTypeEnum $type): ?ContractStateEvent;

    /**
     * Создать событие
     */
    public function createEvent(array $data): ContractStateEvent;
}

