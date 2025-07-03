<?php

namespace App\Services\OpenApi;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Request;

class ResourceToSchemaConverter
{
    private DummyModel $dummy;

    public function __construct()
    {
        $this->dummy = new DummyModel();
    }

    public function convert(string $class): array
    {
        if (!is_subclass_of($class, JsonResource::class)) {
            return [];
        }

        $request = Request::create('/');

        if (is_subclass_of($class, ResourceCollection::class)) {
            $instance = new $class(collect([$this->dummy]));
            $data = $instance->toArray($request);
            // Для коллекции ожидаем список
            if (!$data) {
                return ['type' => 'array', 'items' => ['type' => 'object']];
            }
            $first = is_array($data) ? reset($data) : null;
            return [
                'type'  => 'array',
                'items' => $this->convertDataToSchema($first),
            ];
        }

        $instance = new $class($this->dummy);
        $data = $instance->toArray($request);
        return $this->convertDataToSchema($data);
    }

    private function convertDataToSchema($data): array
    {
        if (is_array($data)) {
            if ($this->isAssoc($data)) {
                $props = [];
                foreach ($data as $key => $value) {
                    $props[$key] = $this->convertDataToSchema($value);
                }
                return [
                    'type'       => 'object',
                    'properties' => $props,
                ];
            }
            // List
            $first = $data ? reset($data) : null;
            return [
                'type'  => 'array',
                'items' => $this->convertDataToSchema($first),
            ];
        }

        if (is_int($data)) {
            return ['type' => 'integer'];
        }
        if (is_float($data)) {
            return ['type' => 'number'];
        }
        if (is_bool($data)) {
            return ['type' => 'boolean'];
        }
        // fallback
        return ['type' => 'string'];
    }

    private function isAssoc(array $arr): bool
    {
        if (array() === $arr) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}

class DummyModel extends \stdClass
{
    public function __get($name)
    {
        return null;
    }
} 