<?php

namespace App\Services\Contract;

use App\Repositories\Interfaces\SpecificationRepositoryInterface;
use App\DTOs\SpecificationDTO;
use App\Models\Specification;

class SpecificationService
{
    public function __construct(
        protected SpecificationRepositoryInterface $repository
    ) {}

    public function create(SpecificationDTO $dto): Specification
    {
        return $this->repository->create($dto->toArray());
    }

    public function update(int $id, SpecificationDTO $dto): bool
    {
        return $this->repository->update($id, $dto->toArray());
    }

    public function delete(int $id): bool
    {
        return $this->repository->delete($id);
    }

    public function getById(int $id): ?Specification
    {
        return $this->repository->find($id);
    }

    public function paginate(int $perPage = 15)
    {
        return $this->repository->paginate($perPage);
    }
} 