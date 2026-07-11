<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use InvalidArgumentException;

final readonly class PipelineStageOutput
{
    public const MAX_BYTES = 4096;

    private function __construct(
        public ProcessingStage $stage,
        public int $schemaVersion,
        public array $data,
        public string $version,
    ) {}

    public static function create(ProcessingStage $stage, int $schemaVersion, array $data): self
    {
        if ($schemaVersion !== 1) {
            throw new InvalidArgumentException('Unsupported pipeline output schema version.');
        }

        $envelope = ['stage' => $stage->value, 'schema_version' => $schemaVersion, 'data' => $data];
        $canonical = CanonicalPipelineJson::encode($envelope);
        if (strlen($canonical) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Pipeline output exceeds the bounded persistence limit.');
        }

        return new self($stage, $schemaVersion, $data, 'sha256:'.hash('sha256', $canonical));
    }

    public static function fromEnvelope(array $envelope, string $expectedVersion): self
    {
        $stage = ProcessingStage::tryFrom((string) ($envelope['stage'] ?? ''));
        $schemaVersion = $envelope['schema_version'] ?? null;
        $data = $envelope['data'] ?? null;
        if ($stage === null || ! is_int($schemaVersion) || ! is_array($data)) {
            throw new InvalidArgumentException('Persisted pipeline output envelope is invalid.');
        }

        $output = self::create($stage, $schemaVersion, $data);
        if (! hash_equals($expectedVersion, $output->version)) {
            throw new InvalidArgumentException('Persisted pipeline output version is invalid.');
        }

        return $output;
    }

    public function envelope(): array
    {
        return ['stage' => $this->stage->value, 'schema_version' => $this->schemaVersion, 'data' => $this->data];
    }
}
