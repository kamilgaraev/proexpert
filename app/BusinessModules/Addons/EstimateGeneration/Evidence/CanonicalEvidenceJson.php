<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

use InvalidArgumentException;

final class CanonicalEvidenceJson
{
    private const MAX_DEPTH = 6;

    private const MAX_KEYS = 1000;

    private const MAX_STRING_BYTES = 16384;

    private const MAX_JSON_BYTES = 65536;

    private const FORBIDDEN_KEYS = [
        'password', 'secret', 'token', 'authorization', 'cookie', 'file_bytes', 'binary', 'base64',
        'raw_text', 'full_text', 'page_text', 'document_text', 'content',
    ];

    public static function normalize(array $value): array
    {
        $keys = 0;
        $normalized = self::walk($value, 1, $keys);
        $json = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new InvalidArgumentException('Evidence JSON is too large.');
        }

        return $normalized;
    }

    private static function walk(mixed $value, int $depth, int &$keys): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('Evidence JSON is too deep.');
        }
        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Evidence JSON contains a non-finite number.');
        }
        if (is_string($value)) {
            if (! mb_check_encoding($value, 'UTF-8') || strlen($value) > self::MAX_STRING_BYTES) {
                throw new InvalidArgumentException('Evidence JSON contains an invalid string.');
            }

            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('Evidence JSON must contain scalar JSON values only.');
        }

        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::walk($item, $depth + 1, $keys), $value);
        }

        $normalized = [];
        $stringKeys = [];
        foreach ($value as $key => $_) {
            if (! is_string($key) || $key === '' || ! mb_check_encoding($key, 'UTF-8')) {
                throw new InvalidArgumentException('Evidence JSON object keys must be non-empty UTF-8 strings.');
            }
            $lower = mb_strtolower($key);
            if (in_array($lower, self::FORBIDDEN_KEYS, true)) {
                throw new InvalidArgumentException('Evidence JSON contains prohibited source material or secrets.');
            }
            $stringKeys[] = $key;
        }
        sort($stringKeys, SORT_STRING);
        foreach ($stringKeys as $key) {
            if (++$keys > self::MAX_KEYS) {
                throw new InvalidArgumentException('Evidence JSON contains too many keys.');
            }
            $normalized[$key] = self::walk($value[$key], $depth + 1, $keys);
        }

        return $normalized;
    }
}
