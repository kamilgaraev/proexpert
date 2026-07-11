<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

use InvalidArgumentException;

final class EvidenceSchema
{
    public static function locator(EvidenceType $type, array $locator): array
    {
        $schema = match ($type) {
            EvidenceType::SourceFact, EvidenceType::Extracted, EvidenceType::Measured => [
                'document_id' => 'positive_int', 'unit_type' => 'key', 'unit_index' => 'positive_int',
                'page' => 'positive_int', 'sheet' => 'key', 'region_key' => 'key', 'element_key' => 'key',
                'bbox' => 'bbox', 'source_key' => 'key',
            ],
            EvidenceType::Inferred => ['inference_key' => 'key', 'item_key' => 'key'],
            EvidenceType::WorkItem, EvidenceType::NormativeMatch, EvidenceType::Price => ['item_key' => 'key'],
        };
        self::assertClosed($locator, $schema, 'locator');
        $hasIdentity = match ($type) {
            EvidenceType::SourceFact, EvidenceType::Extracted, EvidenceType::Measured => isset($locator['document_id']) || isset($locator['source_key']),
            EvidenceType::Inferred => isset($locator['inference_key']),
            default => isset($locator['item_key']),
        };
        if (! $hasIdentity) {
            throw new InvalidArgumentException('Evidence locator identity is missing.');
        }

        return CanonicalEvidenceJson::normalize($locator);
    }

    public static function value(EvidenceType $type, array $value): array
    {
        [$schema, $required] = match ($type) {
            EvidenceType::SourceFact => [['fact_key' => 'key', 'fact_value' => 'normalized_scalar', 'unit' => 'unit'], ['fact_key', 'fact_value']],
            EvidenceType::Extracted => [['field_key' => 'key', 'field_value' => 'normalized_scalar', 'unit' => 'unit'], ['field_key', 'field_value']],
            EvidenceType::Measured => [['quantity' => 'number', 'unit' => 'unit', 'method' => 'key'], ['quantity', 'unit']],
            EvidenceType::Inferred => [['result_code' => 'key', 'confidence_band' => 'key'], ['result_code']],
            EvidenceType::WorkItem => [['work_code' => 'key', 'quantity' => 'number', 'unit' => 'unit'], ['work_code']],
            EvidenceType::NormativeMatch => [['norm_key' => 'key', 'score' => 'confidence', 'dataset_version' => 'key'], ['norm_key', 'score', 'dataset_version']],
            EvidenceType::Price => [['amount' => 'number', 'currency' => 'currency', 'price_version' => 'key', 'region_code' => 'key'], ['amount', 'currency', 'price_version']],
        };
        self::assertClosed($value, $schema, 'value');
        foreach ($required as $key) {
            if (! array_key_exists($key, $value)) {
                throw new InvalidArgumentException('Evidence value field is missing.');
            }
        }

        return CanonicalEvidenceJson::normalize($value);
    }

    private static function assertClosed(array $data, array $schema, string $section): void
    {
        foreach ($data as $key => $value) {
            if (! is_string($key) || ! isset($schema[$key])) {
                throw new InvalidArgumentException('Evidence '.$section.' field is not allowed.');
            }
            self::assertValue($schema[$key], $value);
        }
    }

    private static function assertValue(string $rule, mixed $value): void
    {
        $valid = match ($rule) {
            'positive_int' => is_int($value) && $value > 0 && $value <= 1_000_000,
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value) && abs((float) $value) <= 1_000_000_000_000,
            'confidence' => (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 1,
            'key' => self::boundedString($value, 160, '/^[\pL\pN][\pL\pN_.:\/@-]*$/u'),
            'unit' => self::boundedString($value, 32, '/^[\pL\pN][\pL\pN .\/*%-]*$/u'),
            'currency' => is_string($value) && preg_match('/^[A-Z]{3}$/D', $value) === 1,
            'normalized_scalar' => is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))
                || self::boundedString($value, 160, '/^[\pL\pN][\pL\pN_.:\/@-]*$/u'),
            'bbox' => self::validBbox($value),
            default => false,
        };
        if (! $valid) {
            throw new InvalidArgumentException('Evidence field value is invalid.');
        }
    }

    private static function boundedString(mixed $value, int $max, string $pattern): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $max
            && mb_check_encoding($value, 'UTF-8') && preg_match($pattern, $value) === 1;
    }

    private static function validBbox(mixed $value): bool
    {
        if (! is_array($value) || count($value) !== 4 || ! array_is_list($value)) {
            return false;
        }
        foreach ($value as $coordinate) {
            if ((! is_int($coordinate) && ! is_float($coordinate)) || ! is_finite((float) $coordinate)
                || $coordinate < 0 || $coordinate > 1_000_000) {
                return false;
            }
        }

        return true;
    }
}
