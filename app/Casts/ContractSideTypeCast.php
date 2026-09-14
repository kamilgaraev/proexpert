<?php

declare(strict_types=1);

namespace App\Casts;

use App\Enums\Contract\ContractSideTypeEnum;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;

final class ContractSideTypeCast implements CastsAttributes, SerializesCastableAttributes
{
    public bool $withoutObjectCaching = true;

    public function get(Model $model, string $key, mixed $value, array $attributes): ?ContractSideTypeEnum
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ContractSideTypeEnum::tryFromLegacy((string) $value)
            ?? ContractSideTypeEnum::from((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value instanceof ContractSideTypeEnum
            ? $value->value
            : $this->get($model, $key, $value, $attributes)?->value;
    }

    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $this->set($model, $key, $value, $attributes);
    }
}
