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
            // Попробуем определить базовый ресурс через свойство collects
            $ref = new \ReflectionClass($class);
            $defaults = $ref->getDefaultProperties();
            $collectsClass = $defaults['collects'] ?? null;
            if ($collectsClass && is_string($collectsClass)) {
                $itemSchema = $this->convert($collectsClass);
            } else {
                $itemSchema = ['type' => 'object'];
            }
            return [
                'type'  => 'array',
                'items' => $itemSchema ?: ['type' => 'object'],
            ];
        }

        $instance = new $class($this->dummy);
        try {
            $data = $instance->toArray($request);
        } catch (\Throwable $e) {
            return [];
        }
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
        if (str_ends_with($name, '_at')) {
            return new DummyDate();
        }
        return null;
    }

    public function __call($name, $arguments)
    {
        if ($name === 'relationLoaded') {
            return false;
        }
        return null;
    }
}

class DummyDate {
    public function format($format) {
        return date($format, 0);
    }
} 