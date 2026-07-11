<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

final readonly class BenchmarkCaseData
{
    /**
     * @param  list<string>  $tags
     * @param  list<string>  $allowedCapabilities
     */
    public function __construct(
        public string $id,
        public BenchmarkDatasetType $dataset,
        public BenchmarkSourceType $sourceType,
        public string $inputLocator,
        public string $expectedLocator,
        public string $inputSha256,
        public string $expectedSha256,
        public string $license,
        public string $provenance,
        public array $tags,
        public int $schemaVersion,
        public string $expectedModelSchemaVersion,
        public array $allowedCapabilities,
        private string $fixtureRoot,
    ) {}

    public function isLocallyReadable(): bool
    {
        return $this->dataset !== BenchmarkDatasetType::Acceptance;
    }

    public function inputPath(): string
    {
        return $this->localPath($this->inputLocator);
    }

    public function expectedPath(): string
    {
        return $this->localPath($this->expectedLocator);
    }

    private function localPath(string $locator): string
    {
        if (! $this->isLocallyReadable()) {
            throw new BenchmarkManifestException('acceptance_object_read_forbidden');
        }

        return $this->fixtureRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $locator);
    }
}
