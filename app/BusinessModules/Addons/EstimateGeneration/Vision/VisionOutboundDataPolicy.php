<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionProviderException;

/** Projects only extraction context; tenant, storage, payment and diagnostics stay on the server. */
final class VisionOutboundDataPolicy
{
    public const VERSION = 'vision-outbound-data:v1';

    private const CAPABILITIES = ['text_spans', 'vectors', 'page_render', 'source_coordinates', 'original_raster',
        'ocr_spans', 'image_coordinates', 'layers', 'blocks', 'polylines', 'dimensions', 'texts', 'sheet_render',
        'sheets', 'cells', 'formulas', 'merges', 'table_render'];

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    public static function metadata(array $metadata, bool $arbitration, bool $geometryExpert): array
    {
        $result = [];
        foreach (['representation_status', 'geometry_status'] as $key) {
            if (array_key_exists($key, $metadata)) {
                $result[$key] = self::status($metadata[$key]);
            }
        }
        if (array_key_exists('capabilities', $metadata)) {
            $result['capabilities'] = [];
            foreach (self::object($metadata['capabilities'], self::CAPABILITIES) as $key => $status) {
                $result['capabilities'][$key] = self::status($status);
            }
        }
        if (array_key_exists('source_bounds', $metadata)) {
            $bounds = $metadata['source_bounds'];
            if (! is_array($bounds) || ! array_is_list($bounds) || count($bounds) !== 4) {
                throw new VisionProviderException('vision_outbound_context_invalid');
            }
            foreach ($bounds as $value) {
                if (! is_numeric($value) || ! is_finite((float) $value)) {
                    throw new VisionProviderException('vision_outbound_context_invalid');
                }
            }
            $result['source_bounds'] = $bounds;
        }
        if (is_bool($metadata['native_text_truncated'] ?? null)) {
            $result['native_text_truncated'] = $metadata['native_text_truncated'];
        }
        if ($arbitration) {
            $block = self::scalars($metadata['arbitration'] ?? null, ['contract', 'source_version', 'minority_evidence_required']);
            $block['claims'] = self::items($metadata['arbitration']['claims'] ?? null, self::claim(...));
            $result['arbitration'] = $block;
        } elseif ($geometryExpert) {
            $block = self::scalars($metadata['geometry_expert'] ?? null, ['contract', 'source_version']);
            $input = $metadata['geometry_expert']['arbitration'] ?? null;
            $decisions = self::scalars($input, ['fingerprint']);
            $decisions['decisions'] = self::items(is_array($input) ? ($input['decisions'] ?? null) : null, self::decision(...));
            $block['arbitration'] = $decisions;
            $result['geometry_expert'] = $block;
        }

        return $result;
    }

    private static function status(mixed $value): string
    {
        if (! is_string($value) || (! in_array($value, ['available', 'not_applicable'], true)
            && preg_match('/^unavailable:[a-z][a-z0-9_]{2,80}$/D', $value) !== 1)) {
            throw new VisionProviderException('vision_outbound_context_invalid');
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private static function claim(array $claim): array
    {
        $result = self::scalars($claim, ['id', 'role', 'entity_key', 'fact_type', 'unit', 'evidence_ref', 'explicit_evidence']);
        if (array_key_exists('value', $claim)) {
            $result['value'] = self::scalars($claim['value'], ['type', 'data']);
        }
        if (array_key_exists('locator', $claim)) {
            $result['locator'] = self::scalars($claim['locator'], ['page_id', 'page_number', 'processing_unit_id',
                'source_version', 'coordinate_space', 'document_role', 'explicit']);
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private static function decision(array $decision): array
    {
        $result = self::scalars($decision, ['claim_id', 'status', 'reason_code', 'reason']);
        foreach (['supporting_claim_ids', 'evidence_refs'] as $key) {
            if (array_key_exists($key, $decision)) {
                $ids = $decision[$key];
                if (! is_array($ids) || ! array_is_list($ids) || count($ids) > 192
                    || count(array_filter($ids, 'is_string')) !== count($ids)) {
                    throw new VisionProviderException('vision_outbound_context_invalid');
                }
                $result[$key] = $ids;
            }
        }
        if (array_key_exists('canonical_claim', $decision)) {
            $canonical = $decision['canonical_claim'];
            $result['canonical_claim'] = $canonical === null ? null : self::scalars($canonical,
                ['entity_key', 'fact_type', 'unit', 'source_claim_id']);
            if (is_array($canonical) && array_key_exists('value', $canonical)) {
                $result['canonical_claim']['value'] = self::scalars($canonical['value'], ['type', 'data']);
            }
        }

        return $result;
    }

    /** @param list<string> $keys @return array<string,mixed> */
    private static function object(mixed $value, array $keys): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new VisionProviderException('vision_outbound_context_invalid');
        }

        return array_intersect_key($value, array_flip($keys));
    }

    /** @param list<string> $keys @return array<string,mixed> */
    private static function scalars(mixed $value, array $keys): array
    {
        $result = self::object($value, $keys);
        foreach ($result as $item) {
            if ($item !== null && ! is_scalar($item)) {
                throw new VisionProviderException('vision_outbound_context_invalid');
            }
        }

        return $result;
    }

    /** @param callable(array):array<string,mixed> $project @return list<array<string,mixed>> */
    private static function items(mixed $value, callable $project): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 192) {
            throw new VisionProviderException('vision_outbound_context_invalid');
        }
        $result = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                throw new VisionProviderException('vision_outbound_context_invalid');
            }
            $result[] = $project($item);
        }

        return $result;
    }
}
