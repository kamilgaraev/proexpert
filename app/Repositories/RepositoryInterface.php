<?php

namespace App\Repositories;

interface RepositoryInterface
{
    public function all(array $columns = ['*']);
    
    public function find(int $id, array $columns = ['*']);
    
    public function findBy(string $field, mixed $value, array $columns = ['*']);
    
    public function create(array $data);
    
    public function update(int $id, array $data);
    
    public function delete(int $id);
} 