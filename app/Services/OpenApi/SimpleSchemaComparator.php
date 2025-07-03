<?php

namespace App\Services\OpenApi;

class SimpleSchemaComparator
{
    public function diff(array $expected, array $actual): array
    {
        // expected — формируем из FormRequest (факт), actual — из YAML (док)
        $expectedProps = $expected['properties'] ?? [];
        $actualProps   = $actual['properties'] ?? [];

        $missing = array_diff_key($expectedProps, $actualProps);   // нет в доке
        $obsolete = array_diff_key($actualProps, $expectedProps);  // нет в коде

        $mismatched = [];
        foreach (array_intersect_key($expectedProps, $actualProps) as $name => $prop) {
            $typeExpected = $prop['type'] ?? null;
            $typeActual   = $actualProps[$name]['type'] ?? null;
            if ($typeExpected !== $typeActual) {
                $mismatched[$name] = [
                    'expected' => $typeExpected,
                    'actual'   => $typeActual,
                ];
            }
        }

        return compact('missing', 'obsolete', 'mismatched');
    }
} 