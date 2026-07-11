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
                'document_id' => 'positive_int', 'unit_type' => 'unit_type', 'unit_index' => 'positive_int',
                'page' => 'positive_int', 'sheet' => 'positive_int', 'region_key' => 'region_ref', 'element_key' => 'element_ref',
                'bbox' => 'bbox', 'source_key' => 'source_ref',
            ],
            EvidenceType::Inferred => ['inference_key' => 'inference_ref', 'item_key' => 'item_ref'],
            EvidenceType::WorkItem, EvidenceType::NormativeMatch, EvidenceType::Price => ['item_key' => 'item_ref'],
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
            EvidenceType::SourceFact => [['fact_key' => 'attribute', 'fact_value' => 'normalized_scalar', 'unit' => 'unit'], ['fact_key', 'fact_value']],
            EvidenceType::Extracted => [['field_key' => 'attribute', 'field_value' => 'normalized_scalar', 'unit' => 'unit'], ['field_key', 'field_value']],
            EvidenceType::Measured => [['quantity' => 'nonnegative_number', 'unit' => 'unit', 'method' => 'method'], ['quantity', 'unit']],
            EvidenceType::Inferred => [['result_code' => 'domain_code', 'confidence_band' => 'confidence_band'], ['result_code']],
            EvidenceType::WorkItem => [['work_code' => 'domain_code', 'quantity' => 'nonnegative_number', 'unit' => 'unit'], ['work_code']],
            EvidenceType::NormativeMatch => [['norm_key' => 'norm_ref', 'score' => 'confidence', 'dataset_version' => 'version'], ['norm_key', 'score', 'dataset_version']],
            EvidenceType::Price => [['amount' => 'nonnegative_number', 'currency' => 'currency', 'price_version' => 'version', 'region_code' => 'region_code'], ['amount', 'currency', 'price_version']],
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
            'nonnegative_number' => (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 1_000_000_000_000,
            'confidence' => (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 1,
            'attribute' => is_string($value) && EvidenceAttribute::tryFrom($value) !== null,
            'unit' => is_string($value) && EvidenceUnit::tryFrom($value) !== null,
            'method' => is_string($value) && EvidenceMeasurementMethod::tryFrom($value) !== null,
            'confidence_band' => is_string($value) && EvidenceConfidenceBand::tryFrom($value) !== null,
            'unit_type' => is_string($value) && in_array($value, ['pdf_page', 'spreadsheet_sheet', 'raster_image', 'sketch', 'cad_drawing', 'text_page'], true),
            'region_ref' => self::boundedString($value, 87, '/^region:(?:[1-9][0-9]*|[a-f0-9]{64})$/D'),
            'element_ref' => self::boundedString($value, 88, '/^element:(?:[1-9][0-9]*|[a-f0-9]{64})$/D'),
            'source_ref' => self::boundedString($value, 87, '/^source:(?:[1-9][0-9]*|[a-f0-9]{64})$/D'),
            'inference_ref' => self::boundedString($value, 90, '/^inference:(?:[1-9][0-9]*|[a-f0-9]{64})$/D'),
            'item_ref' => self::boundedString($value, 70, '/^item:(?:[1-9][0-9]*|[a-f0-9]{64})$/D'),
            'domain_code' => is_string($value) && self::validDomainCode($value),
            'norm_ref' => self::boundedString($value, 90, '/^(?:(?:gesn|fer):[0-9]+(?:-[0-9]+){1,5}|fsnb:[0-9]{4}-[1-9][0-9]*)$/D'),
            'version' => is_string($value) && self::validVersion($value),
            'region_code' => is_string($value) && preg_match('/^[0-9]{1,6}$/D', $value) === 1,
            'currency' => is_string($value) && EvidenceCurrency::tryFrom($value) !== null,
            'normalized_scalar' => is_bool($value) || ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 1_000_000_000_000)
                || (is_string($value) && self::validDomainCode($value)),
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

    private static function validDomainCode(string $value): bool
    {
        try {
            new EvidenceDomainCode($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function validVersion(string $value): bool
    {
        try {
            new EvidenceVersion($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
