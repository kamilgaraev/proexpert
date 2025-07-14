<?php

namespace App\Repositories;

use App\Models\Specification;
use App\Repositories\Interfaces\SpecificationRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SpecificationRepository extends BaseRepository implements SpecificationRepositoryInterface
{
    public function __construct()
    {
        parent::__construct(Specification::class);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Specification::orderBy('spec_date', 'desc')->paginate($perPage);
    }

    public function create(array $data): Specification
    {
        /** @var Specification $model */
        $model = parent::create($data);
        return $model;
    }
} 