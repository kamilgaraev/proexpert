<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use InvalidArgumentException;

/** @phpstan-type PipelineMetricValue bool|float|int|string|null|array<string, mixed> */
final readonly class PipelineStageResult
{
    /** @var array<string, PipelineMetricValue> */
    public array $metrics;

    /** @var list<string> */
    public array $warnings;

    /**
     * @param  array<string, PipelineMetricValue>  $metrics
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ProcessingStage $stage,
        public string $outputVersion,
        array $metrics,
        array $warnings = [],
        public ?PipelineStageOutput $output = null,
        public ?array $transientData = null,
    ) {
        PipelineVersionValidator::assertValid($outputVersion, 'output');

        if ($output !== null && ($output->stage !== $stage || ! hash_equals($output->version, $outputVersion))) {
            throw new InvalidArgumentException('Pipeline result output does not match its stage/version.');
        }

        if ($transientData !== null) {
            $canonical = CanonicalPipelineJson::encode($transientData);
            $contentVersion = $output?->data['content_version'] ?? null;
            if (! is_string($contentVersion)
                || ! hash_equals($contentVersion, 'sha256:'.hash('sha256', $canonical))) {
                throw new InvalidArgumentException('Pipeline transient output does not match its artifact reference.');
            }
        }

        $this->metrics = self::copyMetricMap($metrics);
        $this->warnings = self::copyWarnings($warnings);
    }

    /**
     * @param  array<mixed>  $metrics
     * @return array<string, PipelineMetricValue>
     */
    private static function copyMetricMap(array $metrics): array
    {
        $copy = [];

        foreach ($metrics as $name => $value) {
            if (! is_string($name) || preg_match('/\A[a-zA-Z][a-zA-Z0-9_.-]*\z/', $name) !== 1) {
                throw new InvalidArgumentException('Pipeline metric names must use letters, numbers, dots, dashes or underscores.');
            }

            $copy[$name] = self::copyMetricValue($value);
        }

        return $copy;
    }

    /** @return PipelineMetricValue */
    private static function copyMetricValue(mixed $value): array|bool|float|int|string|null
    {
        if (is_array($value)) {
            return self::copyMetricMap($value);
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Pipeline metric values must be finite.');
        }

        return match (true) {
            is_bool($value) => $value ? true : false,
            is_int($value) => $value + 0,
            is_float($value) => $value + 0.0,
            is_string($value) => substr($value, 0),
            $value === null => null,
            default => throw new InvalidArgumentException('Pipeline metrics must contain scalar trees.'),
        };
    }

    /**
     * @param  array<mixed>  $warnings
     * @return list<string>
     */
    private static function copyWarnings(array $warnings): array
    {
        if (! array_is_list($warnings)) {
            throw new InvalidArgumentException('Pipeline warnings must be a list of strings.');
        }

        return array_map(static function (mixed $warning): string {
            if (! is_string($warning)) {
                throw new InvalidArgumentException('Pipeline warnings must be a list of strings.');
            }

            return substr($warning, 0);
        }, $warnings);
    }
}
