<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class ContractRevisionTermsCompiler
{
    private array $fieldTitles = [];

    public function validateBasis(array $resolved): void
    {
        $this->fieldTitles = array_column($resolved['definitions'], 'title', 'id');
        $based = [];
        $walk = function (array $node, bool $inClause = false) use (&$walk, &$based): void {
            $inClause = $inClause || $node['type'] === 'clause';
            if ($inClause && in_array($node['type'], ['variable', 'repeatRows'], true)) {
                $based[$node['attrs']['variableId']] = true;
            }
            foreach ($node['content'] ?? [] as $child) {
                $walk($child, $inClause);
            }
        };
        $walk($resolved['document']);
        foreach ($resolved['definitions'] as $entry) {
            if (isset($entry['definition']['assignment']) && !isset($based[$entry['id']])) {
                $this->invalid($entry['id'], 'basis');
            }
        }
    }

    public function compile(array $revision): array
    {
        $this->fieldTitles = array_column($revision['definitions'], 'title', 'id');
        (new ContractDocumentRenderer)->render($revision['document'], $revision['definitions'], $revision['values'], $revision['entity_snapshots'] ?? []);
        $references = [];
        $visible = [];
        $walk = function (array $node, ?string $clause = null, bool $shown = true) use (&$walk, &$references, &$visible, $revision): void {
            $attrs = $node['attrs'] ?? [];
            if ($node['type'] === 'clause') {
                $clause = $attrs['id'];
            }
            if ($node['type'] === 'conditional') {
                $shown = $shown && ($revision['values'][$attrs['variableId']] ?? null) === true;
            }
            if (in_array($node['type'], ['variable', 'repeatRows'], true)) {
                $id = $attrs['variableId'];
                $references[$id] = true;
                if ($shown) {
                    $visible[$id] ??= [];
                    if ($clause !== null) {
                        $visible[$id][$clause] = true;
                    }
                }
            }
            foreach ($node['content'] ?? [] as $child) {
                $walk($child, $clause, $shown);
            }
        };
        $walk($revision['document']);
        $terms = [];
        $bases = [];
        $works = [];
        $currency = null;
        foreach ($revision['definitions'] as $entry) {
            $definition = $entry['definition'];
            $assignment = $definition['assignment'] ?? null;
            if ($assignment === null) {
                continue;
            }
            $id = $entry['id'];
            if (!isset($references[$id])) {
                $this->invalid($id, 'basis');
            }
            if (!isset($visible[$id])) {
                continue;
            }
            if ($visible[$id] === []) {
                $this->invalid($id, 'basis');
            }
            if (($revision['values'][$id] ?? null) === null) {
                $this->invalid($id, 'missing_value');
            }
            $value = $revision['values'][$id];
            $target = $assignment['target'];
            $key = $target === 'schedule' ? ($assignment['field'] ?? '') : $target;
            if ($key === '' || isset($bases[$key])) {
                $this->invalid($id, 'assignment');
            }
            $bases[$key] = ['variable_id' => $id, 'definition_version' => $entry['version'], 'clause_ids' => array_keys($visible[$id])];
            if (in_array($target, ['subject', 'payment_terms', 'delivery_terms'], true)) {
                if ($definition['type'] !== 'text' || !is_string($value) || trim($value) === '') {
                    $this->invalid($id, 'type');
                }
                $terms[$target] = $value;
            } elseif (in_array($target, ['price', 'advance'], true)) {
                if ($definition['type'] !== 'money') {
                    $this->invalid($id, 'type');
                }
                $this->currency($value['currency'], $currency, $id);
                $terms[$target === 'price' ? 'total_amount' : 'planned_advance_amount'] = $this->decimal($value['amount'], 2, $id);
            } elseif ($target === 'retention') {
                if ($definition['type'] !== 'percentage') {
                    $this->invalid($id, 'type');
                }
                $percentage = $this->decimal($value, 3, $id);
                if (BigDecimal::of($percentage)->isGreaterThan('100')) {
                    $this->invalid($id, 'range');
                }
                $terms['warranty_retention_calculation_type'] = 'percentage';
                $terms['warranty_retention_percentage'] = $percentage;
                $terms['warranty_retention_coefficient'] = null;
            } elseif ($target === 'schedule') {
                if ($definition['type'] !== 'date' || !in_array($key, ['start_date', 'end_date'], true)) {
                    $this->invalid($id, 'type');
                }
                $terms[$key] = $value;
            } elseif ($target === 'works') {
                if ($definition['type'] !== 'table' || !isset($assignment['columns'])) {
                    $this->invalid($id, 'mapping');
                }
                $mapping = $assignment['columns'];
                $workTotal = BigDecimal::zero();
                foreach ($value as $row) {
                    $cells = $row['values'];
                    $name = $cells[$mapping['name']] ?? null;
                    $unit = $cells[$mapping['unit']] ?? null;
                    $price = $cells[$mapping['price']] ?? null;
                    if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 1000
                        || !is_string($unit) || trim($unit) === '' || mb_strlen($unit) > 100 || !is_array($price)) {
                        $this->invalid($id, 'row');
                    }
                    $quantity = $this->decimal($cells[$mapping['quantity']] ?? null, 6, $id);
                    $amount = $this->decimal($price['amount'], 2, $id);
                    $this->currency($price['currency'], $currency, $id);
                    $total = (string) BigDecimal::of($quantity)->multipliedBy($amount)->toScale(2, RoundingMode::HALF_UP);
                    $this->decimal($total, 2, $id);
                    $workTotal = $workTotal->plus($total);
                    $this->decimal((string) $workTotal, 2, $id);
                    $works[] = ['id' => $row['id'], 'variable_id' => $id, 'name' => $name, 'unit' => $unit,
                        'quantity' => $quantity, 'price' => $amount, 'amount' => $total, 'currency' => $currency,
                        'clause_ids' => $bases[$key]['clause_ids']];
                }
            }
        }
        if ($currency !== null) {
            $terms['currency'] = $currency;
        }
        if (isset($terms['start_date'], $terms['end_date']) && $terms['start_date'] > $terms['end_date']) {
            $this->invalid('', 'dates');
        }
        if (isset($terms['planned_advance_amount'], $terms['total_amount'])
            && BigDecimal::of($terms['planned_advance_amount'])->isGreaterThan($terms['total_amount'])) {
            $this->invalid('', 'advance');
        }

        return ['terms' => $terms, 'works' => $works, 'bases' => $bases];
    }

    private function currency(string $value, ?string &$currency, string $id): void
    {
        if ($currency !== null && $currency !== $value) {
            $this->invalid($id, 'currency');
        }
        $currency = $value;
    }

    private function decimal(mixed $value, int $scale, string $id): string
    {
        if (!(new ContractVariableDefinitionValidator)->decimal($value)) {
            $this->invalid($id, 'number');
        }
        $number = BigDecimal::of((string) $value);
        if ($number->isLessThan('0') || $number->isGreaterThan('999999999999.99')) {
            $this->invalid($id, 'range');
        }
        try {
            return (string) $number->toScale($scale, RoundingMode::UNNECESSARY);
        } catch (\Brick\Math\Exception\RoundingNecessaryException) {
            $this->invalid($id, 'precision');
        }
    }

    private function invalid(string $id, string $reason): never
    {
        throw new ContractBuilderException('contracts.revision_terms_invalid_'.$reason, 422,
            ['field_title' => $this->fieldTitles[$id] ?? trans_message('contracts.revision_terms_field')],
            $id !== '' ? 'values.'.$id : null);
    }
}
