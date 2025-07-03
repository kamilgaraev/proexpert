<?php

namespace App\Services\OpenApi;

use BackedEnum;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Illuminate\Database\Eloquent\Model;

class DtoToSchemaConverter
{
    private array $processed = [];

    /**
     * Конвертация класса DTO в JSON-схему.
     */
    public function convert(string $class): array
    {
        if (isset($this->processed[$class])) {
            return $this->processed[$class];
        }

        $ref = new ReflectionClass($class);

        $schema = [
            'type'       => 'object',
            'properties' => [],
            'required'   => [],
        ];

        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            $name = $prop->getName();
            $type = $prop->getType();
            $isNullable = false;
            $schemaType = 'string';
            $schemaProp = [];

            if ($type instanceof ReflectionNamedType) {
                $isNullable = $type->allowsNull();
                $schemaProp = $this->mapNamedType($type->getName());
            }

            if ($isNullable) {
                $schemaProp['nullable'] = true;
            }

            $schema['properties'][$name] = $schemaProp;
            // required если нет null и нет default value
            if (!$isNullable && !$prop->hasDefaultValue()) {
                $schema['required'][] = $name;
            }
        }

        if (!$schema['required']) {
            unset($schema['required']);
        }

        return $this->processed[$class] = $schema;
    }

    private function mapNamedType(string $name): array
    {
        $builtinMap = [
            'int'    => 'integer',
            'float'  => 'number',
            'bool'   => 'boolean',
            'string' => 'string',
            'array'  => 'array',
        ];

        if (isset($builtinMap[$name])) {
            return ['type' => $builtinMap[$name]];
        }

        // Enum
        if (is_subclass_of($name, BackedEnum::class)) {
            $enumType = (new ReflectionClass($name))->getMethod('cases')->invoke(null)[0]->value;
            $schemaType = is_int($enumType) ? 'integer' : 'string';
            return [
                'type' => $schemaType,
                'enum' => array_map(fn($e) => $e->value, $name::cases()),
            ];
        }

        // Eloquent Model -> treat as integer id
        if (is_subclass_of($name, Model::class)) {
            return ['type' => 'integer'];
        }

        // DTO или другой объект -> $ref
        return ['$ref' => '#/components/schemas/' . class_basename($name)];
    }
} 