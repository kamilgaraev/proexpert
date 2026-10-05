<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use Illuminate\Contracts\Translation\Translator;

final class DesignBimLocalizationService
{
    private ?array $dictionary = null;

    public function __construct(private readonly Translator $translator) {}

    public function dictionary(): array
    {
        if ($this->dictionary !== null) {
            return $this->dictionary;
        }

        $dictionary = $this->translator->get('design_bim_labels', [], 'ru');
        if (! is_array($dictionary)) {
            throw new \LogicException('bim_localization_dictionary_missing');
        }
        foreach (['labels', 'categories', 'values', 'material_values'] as $group) {
            $normalized = [];
            foreach ($dictionary[$group] as $key => $value) {
                $normalized[$group === 'labels' ? $this->normalize((string) $key) : $this->exactKey((string) $key)] = $value;
            }
            $dictionary[$group] = $normalized;
        }
        $dictionary['value_fields'] = array_map($this->normalize(...), $dictionary['value_fields']);

        return $this->dictionary = $dictionary;
    }

    public function categoryLabel(?string $category): ?string
    {
        return $category === null ? null : ($this->dictionary()['categories'][$this->exactKey($category)] ?? $category);
    }

    public function present(array $payload): array
    {
        $dictionary = $this->dictionary();

        return [
            'locale' => $dictionary['locale'],
            'schema_version' => $dictionary['schema_version'],
            'fields' => $this->fields($payload, [], [], $dictionary),
        ];
    }

    private function fields(array $data, array $path, array $labels, array $dictionary): array
    {
        $fields = [];
        foreach ($data as $key => $value) {
            $key = (string) $key;
            $nextPath = [...$path, $key];
            $nextLabels = [...$labels, $dictionary['labels'][$this->normalize($key)] ?? $key];
            if (is_array($value) && $value !== []) {
                array_push($fields, ...$this->fields($value, $nextPath, $nextLabels, $dictionary));
            } else {
                $fields[] = [
                    'path' => $nextPath,
                    'label' => implode(' · ', $nextLabels),
                    'value' => $this->value($value, $nextPath, $dictionary),
                ];
            }
        }

        return $fields;
    }

    private function value(mixed $value, array $path, array $dictionary): string
    {
        if ($value === null || $value === [] || $value === '') {
            return $dictionary['empty_value'];
        }
        if (is_bool($value)) {
            return $dictionary[$value ? 'boolean_true' : 'boolean_false'];
        }
        if (is_string($value)) {
            $key = $this->normalize((string) end($path));
            if (in_array($key, ['id', 'globalid', 'guid', 'initialguid', 'reference', 'tag', 'serialnumber', 'profile', 'steelgrade', 'concretegrade'], true)) {
                return $value;
            }
            if (in_array($key, ['category', 'ifctype', 'type'], true)) {
                return $this->categoryLabel($value) ?? $value;
            }
            $context = array_values(array_filter(array_map($this->normalize(...), $path), static fn (string $part): bool => ! ctype_digit($part)));
            $field = (string) end($context);
            if (in_array($field, ['material', 'materials'], true)
                || ($field === 'name' && in_array('materials', $context, true))) {
                return $dictionary['material_values'][$this->exactKey($value)] ?? $value;
            }
            if (in_array($field, $dictionary['value_fields'], true)) {
                return $dictionary['values'][$this->exactKey($value)] ?? $value;
            }
        }

        return (string) $value;
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? $value;
    }

    private function exactKey(string $value): string
    {
        return strtolower(trim($value));
    }
}
