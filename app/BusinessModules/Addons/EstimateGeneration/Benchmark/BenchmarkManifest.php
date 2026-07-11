<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use JsonException;

final readonly class BenchmarkManifest
{
    private const REQUIRED_SOURCE_TYPES = [
        'vector_pdf',
        'scanned_pdf',
        'photo_plan',
        'dimensioned_sketch',
        'undimensioned_sketch',
        'dwg',
        'dxf',
    ];

    /** @param list<BenchmarkCaseData> $cases */
    private function __construct(
        public int $schemaVersion,
        public string $manifestVersion,
        public string $manifestSha256,
        public ?string $acceptanceManifestLocator,
        private array $cases,
    ) {}

    public static function fromFile(string $manifestPath, string $fixtureRoot): self
    {
        $size = @filesize($manifestPath);
        if (! is_int($size) || $size < 2 || $size > 2_000_000) {
            throw new BenchmarkManifestException('manifest_size_invalid');
        }
        $contents = @file_get_contents($manifestPath);
        if (! is_string($contents)) {
            throw new BenchmarkManifestException('manifest_unavailable');
        }

        try {
            $payload = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BenchmarkManifestException('manifest_invalid_json');
        }
        if (! is_array($payload)) {
            throw new BenchmarkManifestException('manifest_invalid_shape');
        }

        return self::fromArray($payload, $fixtureRoot, hash('sha256', $contents));
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(
        array $payload,
        string $fixtureRoot,
        ?string $manifestSha256 = null,
        bool $requireAllSourceTypes = true,
    ): self {
        $allowedKeys = ['schema_version', 'manifest_version', 'cases'];
        if (array_key_exists('acceptance_manifest_locator', $payload)) {
            $allowedKeys[] = 'acceptance_manifest_locator';
        }
        self::assertExactKeys($payload, $allowedKeys, 'manifest');
        if (($payload['schema_version'] ?? null) !== 1) {
            throw new BenchmarkManifestException('manifest_schema_version_unsupported');
        }
        if (! is_string($payload['manifest_version'] ?? null) || ! preg_match('/^[a-z0-9][a-z0-9._:-]{2,63}$/', $payload['manifest_version'])) {
            throw new BenchmarkManifestException('manifest_version_invalid');
        }
        if (! is_array($payload['cases'] ?? null) || $payload['cases'] === [] || count($payload['cases']) > 10_000) {
            throw new BenchmarkManifestException('manifest_cases_required');
        }

        $root = realpath($fixtureRoot);
        if ($root === false || ! is_dir($root)) {
            throw new BenchmarkManifestException('fixture_root_unavailable');
        }

        $cases = [];
        foreach ($payload['cases'] as $casePayload) {
            if (! is_array($casePayload)) {
                throw new BenchmarkManifestException('case_invalid_shape');
            }
            $cases[] = self::caseFromArray($casePayload, $root);
        }

        self::assertDisjoint($cases);
        self::assertLocalObjects($cases, $root);
        if ($requireAllSourceTypes) {
            self::assertRequiredSourceCoverage($cases);
        }
        usort($cases, static fn (BenchmarkCaseData $left, BenchmarkCaseData $right): int => strcmp($left->id, $right->id));

        return new self(
            1,
            $payload['manifest_version'],
            $manifestSha256 ?? hash('sha256', self::canonicalJson($payload)),
            self::acceptanceLocator($payload['acceptance_manifest_locator'] ?? null),
            $cases,
        );
    }

    /** @return list<BenchmarkCaseData> */
    public function casesFor(BenchmarkDatasetType $dataset): array
    {
        return array_values(array_filter(
            $this->cases,
            static fn (BenchmarkCaseData $case): bool => $case->dataset === $dataset,
        ));
    }

    public function case(string $id): BenchmarkCaseData
    {
        foreach ($this->cases as $case) {
            if ($case->id === $id) {
                return $case;
            }
        }

        throw new BenchmarkManifestException('benchmark_case_unknown');
    }

    /** @return list<string> */
    public function sourceTypes(): array
    {
        $types = array_values(array_unique(array_map(
            static fn (BenchmarkCaseData $case): string => $case->sourceType->value,
            $this->cases,
        )));
        sort($types, SORT_STRING);

        return $types;
    }

    public function caseCount(): int
    {
        return count($this->cases);
    }

    /** @return list<BenchmarkCaseData> */
    public function cases(): array
    {
        return $this->cases;
    }

    /** @param array<string, mixed> $payload */
    private static function caseFromArray(array $payload, string $root): BenchmarkCaseData
    {
        self::assertExactKeys($payload, [
            'id', 'dataset', 'source_type', 'input_locator', 'expected_locator',
            'input_sha256', 'expected_sha256', 'license', 'provenance', 'tags',
            'schema_version', 'expected_model_schema_version', 'allowed_capabilities',
        ], 'case');

        $id = self::boundedToken($payload['id'] ?? null, 'case_id_invalid', 3, 96);
        $dataset = BenchmarkDatasetType::tryFrom((string) ($payload['dataset'] ?? ''))
            ?? throw new BenchmarkManifestException('dataset_invalid');
        $sourceType = BenchmarkSourceType::tryFrom((string) ($payload['source_type'] ?? ''))
            ?? throw new BenchmarkManifestException('source_type_invalid');
        $inputLocator = self::locator($payload['input_locator'] ?? null, $dataset, 'input_locator_invalid');
        $expectedLocator = self::locator($payload['expected_locator'] ?? null, $dataset, 'expected_locator_invalid');
        $inputHash = self::hash($payload['input_sha256'] ?? null, 'input_sha256_invalid');
        $expectedHash = self::hash($payload['expected_sha256'] ?? null, 'expected_sha256_invalid');
        $license = self::boundedText($payload['license'] ?? null, 'license_invalid', 1, 128);
        $provenance = self::boundedText($payload['provenance'] ?? null, 'provenance_invalid', 3, 256);
        $tags = self::stringList($payload['tags'] ?? null, 'tags_invalid', false);
        $capabilities = self::stringList($payload['allowed_capabilities'] ?? null, 'capabilities_invalid', false);
        if (($payload['schema_version'] ?? null) !== 1) {
            throw new BenchmarkManifestException('case_schema_version_invalid');
        }
        $modelSchema = self::boundedToken($payload['expected_model_schema_version'] ?? null, 'expected_schema_invalid', 3, 96);

        return new BenchmarkCaseData(
            $id,
            $dataset,
            $sourceType,
            $inputLocator,
            $expectedLocator,
            $inputHash,
            $expectedHash,
            $license,
            $provenance,
            $tags,
            1,
            $modelSchema,
            $capabilities,
            $root,
        );
    }

    /** @param list<BenchmarkCaseData> $cases */
    private static function assertDisjoint(array $cases): void
    {
        $ids = [];
        $locators = [];
        $digestOwners = [];
        foreach ($cases as $case) {
            if (isset($ids[$case->id])) {
                throw new BenchmarkManifestException('duplicate_case_id');
            }
            $ids[$case->id] = $case->dataset->value;
            foreach ([$case->inputLocator, $case->expectedLocator] as $locator) {
                if (isset($locators[$locator])) {
                    throw new BenchmarkManifestException('cross_dataset_locator_overlap');
                }
                $locators[$locator] = $case->dataset->value;
            }
            foreach (['input' => $case->inputSha256, 'expected' => $case->expectedSha256] as $role => $digest) {
                if (isset($digestOwners[$digest])) {
                    throw new BenchmarkManifestException('digest_ownership_collision');
                }
                $digestOwners[$digest] = $case->id.':'.$role;
            }
        }
    }

    /** @param list<BenchmarkCaseData> $cases */
    private static function assertLocalObjects(array $cases, string $root): void
    {
        foreach ($cases as $case) {
            if (! $case->isLocallyReadable()) {
                continue;
            }
            foreach ([[$case->inputPath(), $case->inputSha256, 64_000_000], [$case->expectedPath(), $case->expectedSha256, 4_000_000]] as [$path, $hash, $maxBytes]) {
                self::assertSafePathComponents($path, $root);
                $real = realpath($path);
                $stat = @lstat($path);
                if ($real === false || ! is_file($real) || is_link($path) || ! is_array($stat)
                    || (int) ($stat['nlink'] ?? 1) > 1 || ! self::isWithin($real, $root)) {
                    throw new BenchmarkManifestException('fixture_file_invalid');
                }
                $size = filesize($real);
                if (! is_int($size) || $size < 1 || $size > $maxBytes) {
                    throw new BenchmarkManifestException('fixture_size_invalid');
                }
                if (! hash_equals($hash, (string) hash_file('sha256', $real))) {
                    throw new BenchmarkManifestException('fixture_hash_mismatch');
                }
            }
            $inputBytes = @file_get_contents($case->inputPath());
            if (! is_string($inputBytes)) {
                throw new BenchmarkManifestException('fixture_file_invalid');
            }
            (new BenchmarkFixtureDescriptorValidator)->validateBytes(
                $inputBytes,
                $case->sourceType,
                $case->inputLocator,
                $case->allowedCapabilities,
            );
            try {
                $expectedPayload = json_decode((string) file_get_contents($case->expectedPath()), true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new BenchmarkManifestException('expected_contract_invalid');
            }
            if (! is_array($expectedPayload)) {
                throw new BenchmarkManifestException('expected_contract_invalid');
            }
            try {
                BenchmarkExpectedContract::expected($expectedPayload, $case->expectedModelSchemaVersion);
            } catch (BenchmarkContractException) {
                throw new BenchmarkManifestException('expected_contract_invalid');
            }
        }
    }

    /** @param list<BenchmarkCaseData> $cases */
    private static function assertRequiredSourceCoverage(array $cases): void
    {
        $actual = array_unique(array_map(static fn (BenchmarkCaseData $case): string => $case->sourceType->value, $cases));
        foreach (self::REQUIRED_SOURCE_TYPES as $required) {
            if (! in_array($required, $actual, true)) {
                throw new BenchmarkManifestException('required_source_type_missing:'.$required);
            }
        }
    }

    private static function locator(mixed $value, BenchmarkDatasetType $dataset, string $code): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 512 || str_contains($value, "\0")) {
            throw new BenchmarkManifestException($code);
        }
        if ($dataset === BenchmarkDatasetType::Acceptance) {
            if (! preg_match('#^s3://org-\{organization_id\}/estimate-generation/benchmarks/acceptance/[a-zA-Z0-9._/-]+$#', $value)
                || str_contains($value, '..') || str_contains($value, '?')) {
                throw new BenchmarkManifestException($code);
            }

            return $value;
        }
        if (str_starts_with($value, '/') || str_starts_with($value, '\\') || preg_match('/^[A-Za-z]:/', $value)
            || str_contains(str_replace('\\', '/', $value), '../') || str_contains($value, '://')) {
            throw new BenchmarkManifestException($code);
        }

        return str_replace('\\', '/', $value);
    }

    private static function acceptanceLocator(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)
            || ! preg_match('#^s3://org-\{organization_id\}/estimate-generation/benchmarks/acceptance/[a-zA-Z0-9._/-]+\.json$#', $value)
            || str_contains($value, '..') || str_contains($value, '?')) {
            throw new BenchmarkManifestException('acceptance_manifest_locator_invalid');
        }

        return $value;
    }

    private static function hash(mixed $value, string $code): string
    {
        if (! is_string($value) || ! preg_match('/^[a-f0-9]{64}$/', $value)) {
            throw new BenchmarkManifestException($code);
        }

        return $value;
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $code, bool $allowEmpty): array
    {
        if (! is_array($value) || (! $allowEmpty && $value === []) || count($value) > 32) {
            throw new BenchmarkManifestException($code);
        }
        $result = [];
        foreach ($value as $item) {
            $result[] = self::boundedToken($item, $code, 1, 64);
        }
        if (count($result) !== count(array_unique($result))) {
            throw new BenchmarkManifestException($code);
        }
        sort($result, SORT_STRING);

        return $result;
    }

    private static function boundedToken(mixed $value, string $code, int $min, int $max): string
    {
        $string = self::boundedText($value, $code, $min, $max);
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]*$/', $string)) {
            throw new BenchmarkManifestException($code);
        }

        return $string;
    }

    private static function boundedText(mixed $value, string $code, int $min, int $max): string
    {
        if (! is_string($value) || strlen($value) < $min || strlen($value) > $max || trim($value) !== $value) {
            throw new BenchmarkManifestException($code);
        }

        return $value;
    }

    /** @param array<string, mixed> $payload @param list<string> $keys */
    private static function assertExactKeys(array $payload, array $keys, string $scope): void
    {
        $actual = array_keys($payload);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            throw new BenchmarkManifestException($scope.'_keys_invalid');
        }
    }

    private static function isWithin(string $path, string $root): bool
    {
        $prefix = rtrim(str_replace('\\', '/', $root), '/').'/';

        return str_starts_with(str_replace('\\', '/', $path), $prefix);
    }

    private static function assertSafePathComponents(string $path, string $root): void
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        $normalizedPath = str_replace('\\', '/', $path);
        if (! str_starts_with($normalizedPath, $normalizedRoot.'/')) {
            throw new BenchmarkManifestException('fixture_file_invalid');
        }
        $current = $normalizedRoot;
        foreach (explode('/', substr($normalizedPath, strlen($normalizedRoot) + 1)) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                throw new BenchmarkManifestException('fixture_file_invalid');
            }
            $current .= '/'.$component;
            $stat = @lstat($current);
            if (! is_array($stat) || is_link($current) || (PHP_OS_FAMILY !== 'Windows' && @readlink($current) !== false)
                || (((int) ($stat['mode'] ?? 0)) & 0170000) === 0120000) {
                throw new BenchmarkManifestException('fixture_file_invalid');
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private static function canonicalJson(array $payload): string
    {
        self::sortRecursive($payload);

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param array<mixed> $value */
    private static function sortRecursive(array &$value): void
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                self::sortRecursive($item);
            }
        }
    }
}
