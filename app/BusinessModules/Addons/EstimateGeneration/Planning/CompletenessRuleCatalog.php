<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

use InvalidArgumentException;

final readonly class CompletenessRuleCatalog
{
    private const CLASSIFICATIONS = ['document_missing', 'technology_required', 'optional_recommendation'];

    private array $rules;

    private function __construct(public string $version, public string $contentHash, array $rules)
    {
        $this->rules = $rules;
    }

    public static function fromArray(array $data): self
    {
        $version = trim((string) ($data['version'] ?? ''));
        $rows = $data['rules'] ?? null;
        if ($version === '' || ! is_array($rows) || $rows === [] || count($rows) > 100) {
            throw new InvalidArgumentException('Completeness rule catalog is invalid.');
        }
        $rules = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            $ruleVersion = trim((string) ($row['version'] ?? ''));
            $classification = (string) ($row['classification'] ?? '');
            $applicability = array_values(array_unique($row['applicability_fact_types'] ?? []));
            $package = $row['work_package'] ?? null;
            if ($id === '' || isset($rules[$id]) || $ruleVersion === '' || $applicability === []
                || ! in_array($classification, self::CLASSIFICATIONS, true) || ! is_array($package)
                || count($package['works'] ?? []) > 40 || count($package['materials'] ?? []) > 40
                || count($package['machinery'] ?? []) > 20 || count($package['norm_intents'] ?? []) > 20
                || count($package['quantity_formulas'] ?? []) > 20
                || strlen(json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 1048576) {
                throw new InvalidArgumentException('Completeness rule entry is invalid.');
            }
            foreach ($package['norm_intents'] ?? [] as $intent) {
                if (($intent['max_candidates'] ?? 0) < 1 || $intent['max_candidates'] > 5
                    || count($intent['candidate_refs'] ?? []) > 5) {
                    throw new InvalidArgumentException('Completeness norm candidates are invalid.');
                }
            }
            $canonical = self::canonical($row);
            $rules[$id] = new CompletenessRule(
                $id,
                $ruleVersion,
                hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                $applicability,
                (string) ($row['satisfaction_fact_type'] ?? ''),
                $classification,
                (string) ($row['severity'] ?? 'warning'),
                (string) ($row['impact'] ?? ''),
                $row['exclusion_policy'] ?? [],
                $package,
            );
        }
        $canonical = self::canonical(['version' => $version, 'rules' => $rows]);

        return new self($version, hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), array_values($rules));
    }

    public function rules(): array
    {
        return $this->rules;
    }

    private static function canonical(array $value): array
    {
        ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonical($item);
            }
        }

        return $value;
    }
}
