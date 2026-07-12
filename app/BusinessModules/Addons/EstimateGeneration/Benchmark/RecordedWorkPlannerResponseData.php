<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlannerResponseData;

final readonly class RecordedWorkPlannerResponseData
{
    private const UNITS = ['m', 'm2', 'm3', 'pcs', 'kg', 't', 'h'];

    /** @param list<array<string, mixed>> $sections */
    private function __construct(public array $sections) {}

    public function toWorkPlannerResponse(): WorkPlannerResponseData
    {
        return new WorkPlannerResponseData($this->sections);
    }

    /** @param array<string, mixed> $payload */
    public static function fromProviderArray(array $payload): self
    {
        if (! self::exactKeys($payload, ['schema_version', 'sections'])
            || $payload['schema_version'] !== 'work-planner-v1'
            || ! is_array($payload['sections']) || ! array_is_list($payload['sections'])
            || $payload['sections'] === [] || count($payload['sections']) > 64) {
            throw self::invalid();
        }
        $sections = [];
        $sectionKeys = [];
        $intentKeys = [];
        $quantityKeys = [];
        foreach ($payload['sections'] as $section) {
            if (! is_array($section)
                || ! self::exactKeys($section, ['section_key', 'title', 'scope_type', 'source_refs', 'work_intents'])
                || ! self::token($section['section_key'] ?? null)
                || ! self::text($section['title'] ?? null, 240)
                || ! self::token($section['scope_type'] ?? null)
                || ! self::references($section['source_refs'] ?? null, 32)
                || ! is_array($section['work_intents']) || ! array_is_list($section['work_intents'])
                || $section['work_intents'] === [] || count($section['work_intents']) > 256
                || isset($sectionKeys[$section['section_key']])) {
                throw self::invalid();
            }
            $sectionKeys[$section['section_key']] = true;
            $intents = [];
            foreach ($section['work_intents'] as $intent) {
                if (! is_array($intent)
                    || ! self::exactKeys($intent, ['intent_key', 'quantity_key', 'name', 'category', 'unit', 'quantity', 'quantity_source_refs', 'confidence'])
                    || ! self::token($intent['intent_key'] ?? null)
                    || isset($intentKeys[$intent['intent_key']])
                    || ! self::token($intent['quantity_key'] ?? null)
                    || isset($quantityKeys[$intent['quantity_key']])
                    || ! self::text($intent['name'] ?? null, 500)
                    || ! self::token($intent['category'] ?? null)
                    || ! is_string($intent['unit']) || ! in_array($intent['unit'], self::UNITS, true)
                    || ! is_string($intent['quantity']) || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/D', $intent['quantity']) !== 1
                    || (float) $intent['quantity'] <= 0 || (float) $intent['quantity'] > 1_000_000_000
                    || ! self::references($intent['quantity_source_refs'] ?? null, 32)
                    || $intent['quantity_source_refs'] === []
                    || ! is_int($intent['confidence']) && ! is_float($intent['confidence'])
                    || ! is_finite((float) $intent['confidence'])
                    || (float) $intent['confidence'] < 0 || (float) $intent['confidence'] > 1) {
                    throw self::invalid();
                }
                $intentKeys[$intent['intent_key']] = true;
                $quantityKeys[$intent['quantity_key']] = true;
                $intents[] = $intent;
            }
            $sections[] = [...$section, 'work_intents' => $intents];
        }

        return new self($sections);
    }

    /** @param list<string> $keys */
    private static function exactKeys(array $value, array $keys): bool
    {
        return count($value) === count($keys) && array_diff(array_keys($value), $keys) === [];
    }

    private static function token(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z0-9][a-z0-9._:-]{0,127}$/D', $value) === 1;
    }

    private static function text(mixed $value, int $limit): bool
    {
        return is_string($value) && trim($value) === $value && $value !== '' && mb_strlen($value) <= $limit;
    }

    private static function references(mixed $value, int $limit): bool
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $limit
            || count($value) !== count(array_unique($value))) {
            return false;
        }
        foreach ($value as $reference) {
            if (! is_string($reference) || preg_match('/^[A-Za-z0-9._:-]{1,160}$/D', $reference) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function invalid(): RecordedPortEnvelopeException
    {
        return new RecordedPortEnvelopeException('recorded_work_planner_contract_invalid');
    }
}
