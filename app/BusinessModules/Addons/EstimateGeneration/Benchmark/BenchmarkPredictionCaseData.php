<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use InvalidArgumentException;

final readonly class BenchmarkPredictionCaseData
{
    /**
     * @param list<string> $tags
     * @param list<string> $allowedCapabilities
     * @param array<string, string> $recordedEnvelopeReferences
     * @param array<string, string> $recordedEnvelopeSha256
     */
    public function __construct(
        public string $id,
        public BenchmarkDatasetType $dataset,
        public BenchmarkSourceType $sourceType,
        public string $inputLocator,
        public string $inputSha256,
        public array $tags,
        public array $allowedCapabilities,
        public array $recordedEnvelopeReferences,
        public array $recordedEnvelopeSha256,
    ) {
        self::assertToken($id);
        self::assertLocator($inputLocator);
        self::assertHash($inputSha256);
        self::assertStringList($tags);
        self::assertStringList($allowedCapabilities);
        if (array_keys($recordedEnvelopeReferences) !== array_keys($recordedEnvelopeSha256)) {
            throw new InvalidArgumentException('recorded_envelope_keys_mismatch');
        }
        foreach ($recordedEnvelopeReferences as $port => $locator) {
            self::assertToken($port);
            self::assertLocator($locator);
            self::assertHash($recordedEnvelopeSha256[$port]);
        }
    }

    public static function fromCase(BenchmarkCaseData $case): self
    {
        return new self(
            $case->id,
            $case->dataset,
            $case->sourceType,
            $case->inputLocator,
            $case->inputSha256,
            $case->tags,
            $case->allowedCapabilities,
            [],
            [],
        );
    }

    private static function assertToken(string $value): void
    {
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,95}$/D', $value)) {
            throw new InvalidArgumentException('prediction_case_token_invalid');
        }
    }

    private static function assertLocator(string $value): void
    {
        $normalized = str_replace('\\', '/', $value);
        if (preg_match('#^s3://org-\{organization_id\}/estimate-generation/benchmarks/acceptance/[a-zA-Z0-9._/-]+$#D', $normalized) === 1
            && ! str_contains($normalized, '..') && ! str_contains($normalized, '?')) {
            return;
        }
        if ($value === '' || strlen($value) > 512 || str_contains($value, "\0")
            || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized)
            || str_contains($normalized, '../') || str_contains($normalized, '/..')
            || str_contains($normalized, '://')) {
            throw new InvalidArgumentException('prediction_case_locator_invalid');
        }
    }

    private static function assertHash(string $value): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException('prediction_case_hash_invalid');
        }
    }

    /** @param list<string> $values */
    private static function assertStringList(array $values): void
    {
        if (! array_is_list($values) || count($values) > 32) {
            throw new InvalidArgumentException('prediction_case_list_invalid');
        }
        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException('prediction_case_list_invalid');
            }
            self::assertToken($value);
        }
    }
}
