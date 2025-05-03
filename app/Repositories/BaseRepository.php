<?php

namespace App\Repositories;

use Illuminate\Database\Eloquent\Model;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public function all(array $columns = ['*'])
    {
        return $this->model->all($columns);
    }

    public function find(int $id, array $columns = ['*'])
    {
        return $this->model->find($id, $columns);
    }

    public function findBy(string $field, mixed $value, array $columns = ['*'])
    {
        return $this->model->where($field, $value)->get($columns);
    }

    public function findOneBy(string $field, mixed $value, array $columns = ['*'])
    {
        return $this->model->where($field, $value)->first($columns);
    }

    public function create(array $data)
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data)
    {
        $record = $this->find($id);
        
        if (!$record) {
            return false;
        }
        
        return $record->update($data);
    }

    public function delete(int $id)
    {
        return $this->model->destroy($id);
    }
} 