<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Settings;

use DomainException;

final readonly class EffectiveEstimateGenerationSettings
{
    /** @param array<string, mixed> $snapshot */
    private function __construct(
        public int $snapshotId,
        public string $scope,
        public ?int $organizationId,
        public int $version,
        public string $snapshotHash,
        private array $snapshot,
    ) {}

    /** @param array<string, mixed> $record */
    public static function fromRecord(array $record, int $workOrganizationId): self
    {
        $snapshot = $record['snapshot'] ?? null;
        $scope = $record['scope'] ?? null;
        $organizationId = $record['organization_id'] ?? null;
        $hash = $record['snapshot_hash'] ?? null;
        if (! is_array($snapshot) || ($snapshot['schema_version'] ?? null) !== 2
            || ! in_array($scope, ['global', 'organization'], true)
            || ($scope === 'global' && $organizationId !== null)
            || ($scope === 'organization' && $organizationId !== $workOrganizationId)
            || ! is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1
            || ! hash_equals($hash, SettingsSnapshotHash::calculate($snapshot))) {
            throw new DomainException('estimate_generation_effective_settings_invalid');
        }
        $validated = EstimateGenerationSettingsData::fromArray([
            'scope' => $scope,
            'organization_id' => $organizationId,
            'expected_version' => 0,
            'idempotency_key' => 'runtime-validation',
            ...array_diff_key($snapshot, ['schema_version' => true]),
        ])->snapshot();
        if ($validated !== $snapshot) {
            throw new DomainException('estimate_generation_effective_settings_invalid');
        }

        return new self(
            self::positiveInt($record['snapshot_id'] ?? null),
            $scope,
            is_int($organizationId) ? $organizationId : null,
            self::positiveInt($record['version'] ?? null),
            $hash,
            $snapshot,
        );
    }

    public function model(string $stage): string
    {
        return $this->boundedString('models', $stage);
    }

    public function timeoutSeconds(string $stage): int
    {
        return $this->boundedInt('timeouts', $stage, 1, 3600);
    }

    public function retryAttempts(string $stage): int
    {
        return $this->boundedInt('retries', $stage, 0, 5);
    }

    public function confidence(string $stage): string
    {
        $value = $this->boundedString('confidence', $stage);
        if (preg_match('/^(?:0(?:\.\d{1,4})?|1(?:\.0{1,4})?)$/', $value) !== 1) {
            throw new DomainException('estimate_generation_effective_confidence_invalid');
        }

        return $value;
    }

    public function allowsFormat(string $extension): bool
    {
        return in_array(strtolower($extension), $this->snapshot['enabled_formats'], true);
    }

    public function maxFiles(): int
    {
        return $this->boundedInt('limits', 'max_files', 1, 100);
    }

    public function maxPagesPerFile(): int
    {
        return $this->boundedInt('limits', 'max_pages_per_file', 1, 2000);
    }

    public function maxTotalPages(): int
    {
        return $this->boundedInt('limits', 'max_total_pages', 1, 10000);
    }

    public function dailyBudget(): string
    {
        return $this->money('daily');
    }

    public function monthlyBudget(): string
    {
        return $this->money('monthly');
    }

    public function currency(): string
    {
        $currency = $this->boundedString('budgets', 'currency');
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new DomainException('estimate_generation_effective_currency_invalid');
        }

        return $currency;
    }

    public function requiresManualReview(string $reason): bool
    {
        $value = $this->snapshot['manual_review'][$reason] ?? null;
        if (! is_bool($value)) {
            throw new DomainException('estimate_generation_effective_manual_review_invalid');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->snapshot;
    }

    private static function positiveInt(mixed $value): int
    {
        if (! is_int($value) || $value < 1) {
            throw new DomainException('estimate_generation_effective_settings_identity_invalid');
        }

        return $value;
    }

    private function boundedString(string $section, string $key): string
    {
        $value = $this->snapshot[$section][$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new DomainException('estimate_generation_effective_settings_value_invalid');
        }

        return $value;
    }

    private function boundedInt(string $section, string $key, int $min, int $max): int
    {
        $value = $this->snapshot[$section][$key] ?? null;
        if (! is_int($value) || $value < $min || $value > $max) {
            throw new DomainException('estimate_generation_effective_settings_value_invalid');
        }

        return $value;
    }

    private function money(string $key): string
    {
        $value = $this->boundedString('budgets', $key);
        if (preg_match('/^(?:0|[1-9]\d{0,17})\.\d{2}$/', $value) !== 1) {
            throw new DomainException('estimate_generation_effective_budget_invalid');
        }

        return $value;
    }
}
