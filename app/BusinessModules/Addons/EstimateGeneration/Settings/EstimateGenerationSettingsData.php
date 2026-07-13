<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Settings;

use DomainException;

final readonly class EstimateGenerationSettingsData
{
    private const ROOT_KEYS = [
        'scope', 'organization_id', 'expected_version', 'idempotency_key', 'models', 'limits',
        'timeouts', 'retries', 'confidence', 'enabled_formats', 'manual_review', 'budgets',
    ];

    private const STAGES = ['vision', 'classification', 'planning', 'normative_matching', 'pricing'];

    private const CONFIDENCE_KEYS = ['classification', 'geometry', 'normative_matching', 'pricing'];

    private const REVIEW_KEYS = ['low_confidence', 'missing_evidence', 'price_outlier', 'normative_fallback'];

    private const FORMATS = ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'dxf', 'dwg', 'xlsx'];

    private const CURRENCIES = ['RUB', 'USD', 'EUR'];

    /**
     * @param  array<string, string>  $models
     * @param  array{max_files: int, max_pages_per_file: int, max_total_pages: int}  $limits
     * @param  array<string, int>  $timeouts
     * @param  array<string, int>  $retries
     * @param  array<string, string>  $confidence
     * @param  list<string>  $enabledFormats
     * @param  array<string, bool>  $manualReview
     * @param  array{daily: string, monthly: string, currency: string}  $budgets
     */
    private function __construct(
        public string $scope,
        public ?int $organizationId,
        public int $expectedVersion,
        public string $idempotencyKey,
        public array $models,
        public array $limits,
        public array $timeouts,
        public array $retries,
        public array $confidence,
        public array $enabledFormats,
        public array $manualReview,
        public array $budgets,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        self::assertExactKeys($payload, self::ROOT_KEYS);

        $scope = self::closedString($payload['scope'] ?? null, ['global', 'organization']);
        $organizationId = $payload['organization_id'] ?? null;
        if (($scope === 'global' && $organizationId !== null)
            || ($scope === 'organization' && (! is_int($organizationId) || $organizationId <= 0))) {
            throw new DomainException('estimate_generation_settings_scope_invalid');
        }

        $expectedVersion = $payload['expected_version'] ?? null;
        $idempotencyKey = $payload['idempotency_key'] ?? null;
        if (! is_int($expectedVersion) || $expectedVersion < 0
            || ! is_string($idempotencyKey)
            || preg_match('/^[A-Za-z0-9._:-]{16,80}$/', $idempotencyKey) !== 1) {
            throw new DomainException('estimate_generation_settings_command_invalid');
        }

        $models = self::models($payload['models'] ?? null);
        $limits = self::limits($payload['limits'] ?? null);
        $timeouts = self::stageIntegers($payload['timeouts'] ?? null, 1, 3600, 'timeouts');
        $retries = self::stageIntegers($payload['retries'] ?? null, 0, 5, 'retries');
        $confidence = self::confidence($payload['confidence'] ?? null);
        $enabledFormats = self::formats($payload['enabled_formats'] ?? null);
        $manualReview = self::booleans($payload['manual_review'] ?? null, self::REVIEW_KEYS, 'manual_review');
        $budgets = self::budgets($payload['budgets'] ?? null);

        return new self(
            $scope,
            $scope === 'organization' ? $organizationId : null,
            $expectedVersion,
            $idempotencyKey,
            $models,
            $limits,
            $timeouts,
            $retries,
            $confidence,
            $enabledFormats,
            $manualReview,
            $budgets,
        );
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'schema_version' => 1,
            'models' => $this->models,
            'limits' => $this->limits,
            'timeouts' => $this->timeouts,
            'retries' => $this->retries,
            'confidence' => $this->confidence,
            'enabled_formats' => $this->enabledFormats,
            'manual_review' => $this->manualReview,
            'budgets' => $this->budgets,
        ];
    }

    public function commandFingerprint(): string
    {
        $command = [
            'scope' => $this->scope,
            'organization_id' => $this->organizationId,
            'expected_version' => $this->expectedVersion,
            'snapshot' => $this->snapshot(),
        ];

        return 'sha256:'.hash('sha256', json_encode($command, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, string> */
    private static function models(mixed $value): array
    {
        if (! is_array($value)) {
            throw new DomainException('estimate_generation_settings_models_invalid');
        }
        self::assertExactKeys($value, self::STAGES);
        $models = [];
        foreach (self::STAGES as $stage) {
            $model = $value[$stage] ?? null;
            if (! is_string($model)
                || preg_match('#^[a-z0-9][a-z0-9._-]{1,63}/[a-z0-9][a-z0-9._:-]{1,127}$#i', $model) !== 1
                || preg_match('/(?:secret|token|password|credential|prompt|endpoint|api[_-]?key)/i', $model) === 1) {
                throw new DomainException('estimate_generation_settings_model_invalid');
            }
            $models[$stage] = $model;
        }

        return $models;
    }

    /** @return array{max_files: int, max_pages_per_file: int, max_total_pages: int} */
    private static function limits(mixed $value): array
    {
        $keys = ['max_files', 'max_pages_per_file', 'max_total_pages'];
        if (! is_array($value)) {
            throw new DomainException('estimate_generation_settings_limits_invalid');
        }
        self::assertExactKeys($value, $keys);
        foreach ($keys as $key) {
            if (! is_int($value[$key] ?? null) || $value[$key] <= 0) {
                throw new DomainException('estimate_generation_settings_limits_invalid');
            }
        }
        if ($value['max_files'] > 100 || $value['max_pages_per_file'] > 2000 || $value['max_total_pages'] > 10000) {
            throw new DomainException('estimate_generation_settings_limits_invalid');
        }

        return $value;
    }

    /** @return array<string, int> */
    private static function stageIntegers(mixed $value, int $min, int $max, string $kind): array
    {
        if (! is_array($value)) {
            throw new DomainException("estimate_generation_settings_{$kind}_invalid");
        }
        self::assertExactKeys($value, self::STAGES);
        foreach (self::STAGES as $stage) {
            if (! is_int($value[$stage] ?? null) || $value[$stage] < $min || $value[$stage] > $max) {
                throw new DomainException("estimate_generation_settings_{$kind}_invalid");
            }
        }

        return $value;
    }

    /** @return array<string, string> */
    private static function confidence(mixed $value): array
    {
        if (! is_array($value)) {
            throw new DomainException('estimate_generation_settings_confidence_invalid');
        }
        self::assertExactKeys($value, self::CONFIDENCE_KEYS);
        foreach (self::CONFIDENCE_KEYS as $key) {
            $threshold = $value[$key] ?? null;
            if (! is_string($threshold)
                || preg_match('/^(?:0(?:\.\d{1,4})?|1(?:\.0{1,4})?)$/', $threshold) !== 1) {
                throw new DomainException('estimate_generation_settings_confidence_invalid');
            }
        }

        return $value;
    }

    /** @return list<string> */
    private static function formats(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) !== count(array_unique($value))) {
            throw new DomainException('estimate_generation_settings_formats_invalid');
        }
        foreach ($value as $format) {
            if (! is_string($format) || ! in_array($format, self::FORMATS, true)) {
                throw new DomainException('estimate_generation_settings_formats_invalid');
            }
        }

        return $value;
    }

    /** @param list<string> $keys @return array<string, bool> */
    private static function booleans(mixed $value, array $keys, string $kind): array
    {
        if (! is_array($value)) {
            throw new DomainException("estimate_generation_settings_{$kind}_invalid");
        }
        self::assertExactKeys($value, $keys);
        foreach ($keys as $key) {
            if (! is_bool($value[$key] ?? null)) {
                throw new DomainException("estimate_generation_settings_{$kind}_invalid");
            }
        }

        return $value;
    }

    /** @return array{daily: string, monthly: string, currency: string} */
    private static function budgets(mixed $value): array
    {
        $keys = ['daily', 'monthly', 'currency'];
        if (! is_array($value)) {
            throw new DomainException('estimate_generation_settings_budgets_invalid');
        }
        self::assertExactKeys($value, $keys);
        foreach (['daily', 'monthly'] as $key) {
            if (! is_string($value[$key] ?? null) || preg_match('/^(?:0|[1-9]\d{0,17})\.\d{2}$/', $value[$key]) !== 1) {
                throw new DomainException('estimate_generation_settings_budget_invalid');
            }
        }
        if (self::decimalCompare($value['daily'], $value['monthly']) > 0) {
            throw new DomainException('estimate_generation_settings_budget_invalid');
        }
        self::closedString($value['currency'] ?? null, self::CURRENCIES);

        return $value;
    }

    /** @param array<mixed> $value @param list<string> $keys */
    private static function assertExactKeys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual);
        $expected = $keys;
        sort($expected);
        if ($actual !== $expected) {
            throw new DomainException('estimate_generation_settings_schema_invalid');
        }
    }

    /** @param list<string> $allowed */
    private static function closedString(mixed $value, array $allowed): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new DomainException('estimate_generation_settings_enum_invalid');
        }

        return $value;
    }

    private static function decimalCompare(string $left, string $right): int
    {
        [$leftWhole, $leftFraction] = explode('.', $left);
        [$rightWhole, $rightFraction] = explode('.', $right);
        $length = max(strlen($leftWhole), strlen($rightWhole));

        return strcmp(str_pad($leftWhole, $length, '0', STR_PAD_LEFT).$leftFraction, str_pad($rightWhole, $length, '0', STR_PAD_LEFT).$rightFraction);
    }
}
