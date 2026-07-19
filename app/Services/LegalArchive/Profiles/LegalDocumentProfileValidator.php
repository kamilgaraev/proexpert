<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Profiles;

use DateTimeImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

use function trans_message;

final class LegalDocumentProfileValidator
{
    private const ALLOWED_TYPES = ['string', 'integer', 'number', 'boolean', 'date', 'array', 'enum'];

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function validate(LegalDocumentProfile $profile, array $fields): array
    {
        $schema = $this->validatedSchema($profile);
        $errors = [];

        foreach ($fields as $field => $value) {
            if (! array_key_exists($field, $schema)) {
                $errors[$field][] = trans_message('legal_archive.profiles.field_unknown', ['field' => $field]);
            }
        }

        foreach ($profile->requiredFields as $field) {
            $value = $fields[$field] ?? null;

            if ($value === null || $value === '' || $value === []) {
                $label = (string) ($schema[$field]['label'] ?? $field);
                $errors[$field][] = trans_message('legal_archive.profiles.field_required', ['field' => $label]);
            }
        }

        $normalized = [];

        foreach ($fields as $field => $value) {
            if (! isset($schema[$field])) {
                continue;
            }

            try {
                $normalized[$field] = $this->normalizeValue($value, $schema[$field]);
            } catch (InvalidArgumentException) {
                $label = (string) ($schema[$field]['label'] ?? $field);
                $errors[$field][] = trans_message('legal_archive.profiles.field_invalid', ['field' => $label]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /** @return array<string, array<string, mixed>> */
    private function validatedSchema(LegalDocumentProfile $profile): array
    {
        foreach ($profile->schema as $field => $definition) {
            if (
                ! is_string($field)
                || $field === ''
                || ! is_array($definition)
                || $this->containsExecutableValue($definition)
                || ! in_array($definition['type'] ?? null, self::ALLOWED_TYPES, true)
                || ! is_string($definition['label'] ?? null)
            ) {
                throw new InvalidArgumentException(trans_message('legal_archive.profiles.schema_invalid'));
            }

            if (($definition['type'] ?? null) === 'enum' && ! $this->isScalarList($definition['options'] ?? null)) {
                throw new InvalidArgumentException(trans_message('legal_archive.profiles.schema_invalid'));
            }

            if (
                ($definition['type'] ?? null) === 'array'
                && isset($definition['items'])
                && ! in_array($definition['items'], ['string', 'integer', 'number', 'boolean'], true)
            ) {
                throw new InvalidArgumentException(trans_message('legal_archive.profiles.schema_invalid'));
            }
        }

        foreach ($profile->requiredFields as $requiredField) {
            if (! array_key_exists($requiredField, $profile->schema)) {
                throw new InvalidArgumentException(trans_message('legal_archive.profiles.schema_invalid'));
            }
        }

        return $profile->schema;
    }

    /** @param array<string, mixed> $definition */
    private function normalizeValue(mixed $value, array $definition): mixed
    {
        return match ($definition['type']) {
            'string' => $this->normalizeString($value),
            'integer' => $this->normalizeInteger($value),
            'number' => $this->normalizeNumber($value),
            'boolean' => $this->normalizeBoolean($value),
            'date' => $this->normalizeDate($value),
            'array' => $this->normalizeArray($value, $definition['items'] ?? null),
            'enum' => $this->normalizeEnum($value, $definition['options']),
            default => throw new InvalidArgumentException,
        };
    }

    private function normalizeString(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException;
        }

        return trim((string) $value);
    }

    private function normalizeInteger(mixed $value): int
    {
        $normalized = filter_var($value, FILTER_VALIDATE_INT);

        if ($normalized === false) {
            throw new InvalidArgumentException;
        }

        return $normalized;
    }

    private function normalizeNumber(mixed $value): float
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException;
        }

        return (float) $value;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        $normalized = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($normalized === null) {
            throw new InvalidArgumentException;
        }

        return $normalized;
    }

    private function normalizeDate(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException;
        }

        return $value;
    }

    /** @return list<mixed> */
    private function normalizeArray(mixed $value, mixed $itemType): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException;
        }

        if ($itemType === null) {
            return $value;
        }

        return array_map(
            fn (mixed $item): mixed => $this->normalizeValue($item, ['type' => $itemType]),
            $value,
        );
    }

    /** @param list<scalar> $options */
    private function normalizeEnum(mixed $value, array $options): mixed
    {
        if (! is_scalar($value) || ! in_array($value, $options, true)) {
            throw new InvalidArgumentException;
        }

        return $value;
    }

    /** @param array<mixed> $value */
    private function containsExecutableValue(array $value): bool
    {
        foreach ($value as $item) {
            if (is_object($item) || is_resource($item)) {
                return true;
            }

            if (is_array($item) && $this->containsExecutableValue($item)) {
                return true;
            }
        }

        return false;
    }

    private function isScalarList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_scalar($item)) {
                return false;
            }
        }

        return true;
    }
}
