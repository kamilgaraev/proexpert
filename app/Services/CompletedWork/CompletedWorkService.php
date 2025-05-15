<?php

namespace App\Services\CompletedWork;

use App\Models\CompletedWork;
use App\DTOs\CompletedWork\CompletedWorkDTO;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Repositories\Interfaces\CompletedWorkRepositoryInterface; // Предполагаем наличие репозитория
use App\Exceptions\BusinessLogicException;
use Illuminate\Support\Facades\Auth; // Для получения organization_id в create

class CompletedWorkService
{
    protected CompletedWorkRepositoryInterface $completedWorkRepository;

    public function __construct(CompletedWorkRepositoryInterface $completedWorkRepository)
    {
        $this->completedWorkRepository = $completedWorkRepository;
    }

    public function getAll(array $filters = [], int $perPage = 15, string $sortBy = 'completion_date', string $sortDirection = 'desc', array $relations = []): LengthAwarePaginator
    {
        // Добавляем сортировку по умолчанию для выполненных работ
        return $this->completedWorkRepository->getAllPaginated($filters, $perPage, $sortBy, $sortDirection, $relations);
    }

    public function getById(int $id, int $organizationId): CompletedWork
    {
        $completedWork = $this->completedWorkRepository->findById($id, $organizationId);
        if (!$completedWork) {
            throw new BusinessLogicException('Запись о выполненной работе не найдена.', 404);
        }
        return $completedWork;
    }

    public function create(CompletedWorkDTO $dto): CompletedWork
    {
        // Предполагаем, что DTO уже содержит organization_id, установленный в FormRequest
        // или мы можем установить его здесь, если это не так.
        // $data = $dto->toArray();
        // if (!isset($data['organization_id'])) {
        //     $data['organization_id'] = Auth::user()->current_organization_id; 
        // }
        // $createdModel = $this->completedWorkRepository->create($data);

        $createdModel = $this->completedWorkRepository->create($dto->toArray());

        if (!$createdModel) {
            // Эта проверка избыточна, если create репозитория всегда возвращает Model или кидает Exception
            // В BaseRepository create возвращает ?Model, так что проверка имеет смысл
            throw new BusinessLogicException('Не удалось создать запись о выполненной работе.', 500);
        }
        return $createdModel; // BaseRepository->create возвращает ?Model, нужно приведение типа
    }

    public function update(int $id, CompletedWorkDTO $dto): CompletedWork
    {
        // Убедимся, что работа существует и принадлежит организации из DTO (или текущей)
        $existingWork = $this->getById($id, $dto->organization_id);

        $success = $this->completedWorkRepository->update($id, $dto->toArray());
        if (!$success) {
            throw new BusinessLogicException('Не удалось обновить запись о выполненной работе.', 500);
        }
        return $existingWork->refresh(); // Возвращаем обновленную модель
    }

    public function delete(int $id, int $organizationId): bool
    {
        // Проверяем существование и принадлежность перед удалением
        $this->getById($id, $organizationId);
        
        $success = $this->completedWorkRepository->delete($id);
        if (!$success) {
            // Это может произойти, если запись была удалена между getById и delete, 
            // или если delete в репозитории вернул false по другой причине.
            // BaseRepository->delete() возвращает $this->find($modelId)?->delete() ?? false;
            // То есть, если find не нашел, вернет false. getById уже это проверил.
            throw new BusinessLogicException('Не удалось удалить запись о выполненной работе.', 500);
        }
        return true;
    }
} 