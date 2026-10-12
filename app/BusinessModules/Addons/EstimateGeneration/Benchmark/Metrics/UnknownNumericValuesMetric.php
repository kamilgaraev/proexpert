<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics;

/** Empty expected numeric maps alone must not reward invented measurements or a substituted zero. */
final class UnknownNumericValuesMetric implements MetricCalculator
{
    public function name(): string
    {
        return 'unknown_numeric_values_preserved';
    }

    public function calculate(array $expected, array $prediction, bool $technicalSuccess): MetricResultData
    {
        $fields = MetricInput::stringList($expected, 'unknown_numeric_fields');
        $matches = 0;
        foreach ($fields as $field) {
            if (! in_array($field, ['areas', 'quantities', 'costs'], true) || MetricInput::decimalMap($expected, $field) !== []) {
                throw new \InvalidArgumentException('unknown_numeric_fields_invalid');
            }
            if ($technicalSuccess && MetricInput::decimalMap($prediction, $field) === []) {
                $matches++;
            }
        }
        $denominator = count($fields);
        $value = $denominator === 0 ? 1.0 : $matches / $denominator;

        return new MetricResultData($this->name(), $value, $value, (float) $matches, $denominator);
    }
}
