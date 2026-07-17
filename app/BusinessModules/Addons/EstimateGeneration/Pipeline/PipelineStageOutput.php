<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use InvalidArgumentException;

final readonly class PipelineStageOutput
{
    public const MAX_ENVELOPE_BYTES = 4096;

    private function __construct(
        public ProcessingStage $stage,
        public int $schemaVersion,
        public string $inputVersion,
        public array $dependencyVersions,
        public PipelineArtifactReference $artifact,
        public string $version,
    ) {}

    /** @param array<string, string> $dependencyVersions */
    public static function create(
        StageDefinition $definition,
        string $inputVersion,
        array $dependencyVersions,
        PipelineArtifactReference $artifact,
    ): self {
        PipelineVersionValidator::assertSha256($inputVersion, 'stage input');
        $expected = array_map(static fn (ProcessingStage $dependency): string => $dependency->value, $definition->dependencies);
        if (array_keys($dependencyVersions) !== $expected || $artifact->bytes > $definition->maxArtifactBytes) {
            throw new InvalidArgumentException('Pipeline stage output dependency manifest or artifact bound is invalid.');
        }
        foreach ($dependencyVersions as $version) {
            PipelineVersionValidator::assertSha256($version, 'dependency output');
        }
        $envelope = [
            'stage' => $definition->stage->value,
            'schema_version' => $definition->schemaVersion,
            'input_version' => $inputVersion,
            'dependency_versions' => $dependencyVersions,
            'artifact' => $artifact->toArray(),
        ];
        $canonical = CanonicalPipelineJson::encode($envelope);
        if (strlen($canonical) > self::MAX_ENVELOPE_BYTES) {
            throw new InvalidArgumentException('Pipeline stage output envelope exceeds its bound.');
        }

        return new self(
            $definition->stage,
            $definition->schemaVersion,
            $inputVersion,
            $dependencyVersions,
            $artifact,
            'sha256:'.hash('sha256', $canonical),
        );
    }

    public static function fromEnvelope(array $envelope, string $expectedVersion): self
    {
        $envelope = self::normalizeEnvelope($envelope);

        if (array_keys($envelope) !== ['stage', 'schema_version', 'input_version', 'dependency_versions', 'artifact']
            || ! is_string($envelope['stage']) || ! is_int($envelope['schema_version'])
            || ! is_string($envelope['input_version']) || ! is_array($envelope['dependency_versions'])
            || ! is_array($envelope['artifact'])) {
            throw new InvalidArgumentException('Persisted pipeline output envelope is invalid.');
        }
        $stage = ProcessingStage::tryFrom($envelope['stage']);
        if ($stage === null) {
            throw new InvalidArgumentException('Persisted pipeline output stage is invalid.');
        }
        $definition = PipelineDefinitionGraph::standard()->get($stage);
        if ($definition->schemaVersion !== $envelope['schema_version']) {
            throw new InvalidArgumentException('Persisted pipeline output schema is stale.');
        }
        $output = self::create(
            $definition,
            $envelope['input_version'],
            $envelope['dependency_versions'],
            PipelineArtifactReference::fromArray($envelope['artifact']),
        );
        if (! hash_equals($expectedVersion, $output->version)) {
            throw new InvalidArgumentException('Persisted pipeline output version is invalid.');
        }

        return $output;
    }

    public function envelope(): array
    {
        return [
            'stage' => $this->stage->value,
            'schema_version' => $this->schemaVersion,
            'input_version' => $this->inputVersion,
            'dependency_versions' => $this->dependencyVersions,
            'artifact' => $this->artifact->toArray(),
        ];
    }

    private static function normalizeEnvelope(array $envelope): array
    {
        if (isset($envelope['schema_version']) && is_string($envelope['schema_version'])) {
            $envelope['schema_version'] = self::numericStringToInt($envelope['schema_version']);
        }
        if (isset($envelope['artifact']) && is_array($envelope['artifact'])
            && isset($envelope['artifact']['bytes']) && is_string($envelope['artifact']['bytes'])) {
            $envelope['artifact']['bytes'] = self::numericStringToInt($envelope['artifact']['bytes']);
        }

        return $envelope;
    }

    private static function numericStringToInt(string $value): int|string
    {
        if ($value === '' || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) !== 1) {
            return $value;
        }
        $integer = (int) $value;

        return (string) $integer === $value ? $integer : $value;
    }
}
