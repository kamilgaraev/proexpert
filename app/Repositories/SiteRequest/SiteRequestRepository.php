<?php

namespace App\Repositories\SiteRequest; // Неймспейс для конкретной реализации

use App\Models\SiteRequest;
use App\Repositories\BaseRepository;
use App\Repositories\Interfaces\SiteRequestRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SiteRequestRepository extends BaseRepository implements SiteRequestRepositoryInterface
{
    public function __construct()
    {
        parent::__construct(SiteRequest::class);
    }

    // getAllPaginated будет унаследован от BaseRepository.
    // Если нужна кастомная логика фильтрации/сортировки сверх базовой,
    // ее можно переопределить здесь, но сигнатура должна совпадать с интерфейсом.
    // public function getAllPaginated(...): LengthAwarePaginator { ... }

    public function findById(int $id, int $organizationId, array $relations = []): ?SiteRequest
    {
        /** @var ?SiteRequest $siteRequest */
        $siteRequest = $this->model->query()
            ->where('id', $id)
            ->where('organization_id', $organizationId)
            ->with($relations) // Загрузка связей
            ->first();
        return $siteRequest;
    }

    // create, update, delete наследуются от BaseRepository
} 