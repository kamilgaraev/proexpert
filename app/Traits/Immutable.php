<?php

namespace App\Traits;

use App\Exceptions\ImmutableDataException;

/**
 * Трейт для неизменяемых моделей.
 * Запрещает обновление и удаление записей после создания.
 * Используется для финансовых транзакций и аудита.
 */
trait Immutable
{
    /**
     * Запретить обновление существующей записи
     */
    public function save(array $options = [])
    {
        if ($this->exists) {
            throw new ImmutableDataException(static::class, 'update');
        }

        return parent::save($options);
    }

    /**
     * Запретить обновление
     */
    public function update(array $attributes = [], array $options = [])
    {
        throw new ImmutableDataException(static::class, 'update');
    }

    /**
     * Запретить удаление
     */
    public function delete()
    {
        throw new ImmutableDataException(static::class, 'delete');
    }

    /**
     * Запретить жесткое удаление
     */
    public function forceDelete()
    {
        throw new ImmutableDataException(static::class, 'delete');
    }

    /**
     * Запретить восстановление (если используется SoftDeletes)
     */
    public function restore()
    {
        throw new ImmutableDataException(static::class, 'restore');
    }
}

