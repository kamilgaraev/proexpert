<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

use App\BusinessModules\Features\AIAssistant\Services\AssistantFactIntentClassifier;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class AssistantFinancialAnswerService
{
    public function __construct(private readonly AssistantEstimateResolver $resolver, private readonly AssistantEstimateEvidenceService $evidence) {}

    public function supports(string $query, ?int $pinnedId = null): bool
    {
        if (AssistantFactIntentClassifier::isNarrative($query) || AssistantFactIntentClassifier::requiresStructuredRead($query)) {
            return false;
        }
        $financial = (bool) preg_match('/(?:позиц|прям[а-яё]*\s+затрат|накладн|сметн[а-яё]*\s+прибыл|сумм|стоимост|(?<![\pL\pN])цен[а-яё]*|ден[еь]г|итог|количеств|объ[её]м|ндс)/iu', $query);
        if (! $financial && preg_match('/(?:открой|открыть|перейди|ссылк|найди|что\s+такое|зачем|для\s+чего|как\s+(?:создать|изменить|настроить|удалить))/iu', $query)) {
            return false;
        }
        if (! preg_match('/смет/iu', $query) && preg_match('/(?:договор|сотрудник|персонал|проект|плат[её]ж|накладн[а-яё]*\s+документ|склад|заявк|акт[а-яё]*\s+работ|график|задач)/iu', $query)) {
            return false;
        }

        return (bool) preg_match('/(?:смет|позиц|прям[а-яё]*\s+затрат|накладн|сметн[а-яё]*\s+прибыл)/iu', $query)
            || (bool) preg_match('/^\s*[\pL]{1,12}-\d[\pL\pN._\/\-]*\s*$/u', $query)
            || ($pinnedId !== null && (bool) preg_match('/(?:сумм|стоимост|(?<![\pL\pN])цен[а-яё]*|ден[еь]г|итог|подробнее|конкрет|какие|количеств|объ[её]м|ндс)/iu', $query));
    }

    public function isApplicable(string $query, ?int $pinnedId = null): bool
    {
        return $this->supports($query, $pinnedId);
    }

    public function answer(string $query, int $organizationId, User $actor, ?int $pinnedId = null, ?array $selectionContext = null): array
    {
        $resolution = $this->resolver->resolve($query, $organizationId, $actor, $pinnedId);
        if ($resolution['status'] !== 'resolved') {
            $text = trans_message('ai_assistant_financial.'.$resolution['status']);
            foreach ($resolution['options'] as $option) {
                $text .= "\n".$this->markdownText($option['number']).' — '.$this->markdownText($option['name']);
            }

            return ['text' => $text, 'validation_status' => 'partial', 'source_refs' => [],
                'pinned_estimate_id' => null, 'resolution' => $resolution, 'needs_clarification' => true];
        }
        try {
            $evidence = $this->evidence->snapshot($resolution['estimate_id'], $organizationId, $actor);
        } catch (AuthorizationException) {
            return ['text' => trans_message('ai_assistant_financial.forbidden'), 'validation_status' => 'partial',
                'source_refs' => [], 'pinned_estimate_id' => null, 'needs_clarification' => true, 'resolution' => ['status' => 'forbidden']];
        }

        $filter = $this->positionFilter($query, $evidence['positions']);
        $highestCostRequested = $this->requestsHighestCostPosition($query);
        if ($highestCostRequested) {
            $filter = [];
        }
        $resetFilter = (bool) preg_match('/(?:все\s+позиц|без\s+фильтра|полн[а-яё]*\s+смет)/iu', $query);
        $numbers = preg_match('/позиц[а-яё]*\s*(?:№\s*)?(\d+(?:\.\d+)*)/iu', $query, $positionMatch) ? [$positionMatch[1]] : [];
        if ($highestCostRequested) {
            $numbers = [];
        }
        if (! $highestCostRequested && $filter === [] && $numbers === []
            && ($selectionContext['estimate_id'] ?? null) === $resolution['estimate_id'] && ! $resetFilter) {
            $filter = array_values(array_filter($selectionContext['position_filter'] ?? [], static fn ($term): bool => is_string($term) && mb_strlen($term) >= 3));
            $numbers = array_values(array_filter($selectionContext['position_numbers'] ?? [], static fn ($number): bool => is_string($number) && (bool) preg_match('/^\d+(?:\.\d+)*$/D', $number)));
        }
        $selection = ['estimate_id' => $resolution['estimate_id'], 'position_filter' => $filter, 'position_numbers' => $numbers];
        $evidence['selection'] = $selection;
        if ($highestCostRequested) {
            $highestPositions = $this->highestCostPositions($evidence['positions']);
            if ($highestPositions === null || $highestPositions === []) {
                return ['text' => trans_message('ai_assistant_financial.highest_position_unverified'),
                    'validation_status' => 'partial', 'source_refs' => [], 'pinned_estimate_id' => $resolution['estimate_id'],
                    'resolution' => $resolution, 'needs_clarification' => true, 'selection' => $selection];
            }
            $evidence['display_position_ids'] = array_map(static fn (array $position): int => (int) $position['id'], $highestPositions);
        }
        if ($filter !== [] || $numbers !== []) {
            $matching = array_filter($evidence['positions'], static function (array $position) use ($filter, $numbers): bool {
                if ($numbers !== [] && ! in_array($position['position_number'], $numbers, true)) {
                    return false;
                }
                foreach ($filter as $term) {
                    if (! str_contains(mb_strtolower($position['name']), $term)) {
                        return false;
                    }
                }

                return true;
            });
            if ($matching === [] || count($matching) > 50) {
                $evidence['validation_status'] = 'partial';
            }
        } elseif ($evidence['position_count'] > 50 && preg_match('/(?:позиц|какие|конкрет|подробнее)/iu', $query)) {
            $evidence['validation_status'] = 'partial';
        }

        $evidence['source_refs'] = AssistantEstimateEvidenceService::publicSourceReferences($evidence, $this->selectedPositions($query, $evidence));

        return ['text' => $this->format($query, $evidence), 'validation_status' => $evidence['validation_status'],
            'source_refs' => $evidence['source_refs'], 'pinned_estimate_id' => $resolution['estimate_id'],
            'resolution' => $resolution, 'financial_evidence' => $evidence, 'fetched_at' => $evidence['fetched_at'],
            'needs_clarification' => false, 'selection' => $selection];
    }

    public function format(string $query, array $evidence): string
    {
        $estimate = $evidence['estimate'];
        $lines = [trans_message('ai_assistant_financial.estimate', ['number' => $this->markdownText($estimate['number']), 'name' => $this->markdownText($estimate['name'])])];
        if (array_key_exists('status', $estimate) || array_key_exists('estimate_date', $estimate)) {
            $statusKey = 'budget_estimates.mobile.statuses.'.($estimate['status'] ?? '');
            $lines[] = trans_message('ai_assistant_financial.identity', [
                'date' => $estimate['estimate_date'] ?? trans_message('ai_assistant_financial.unknown'),
                'status' => \Illuminate\Support\Facades\Lang::has($statusKey)
                    ? $this->markdownText(trans_message($statusKey))
                    : trans_message('ai_assistant_financial.unknown'),
            ]);
        }
        $lines[] = trans_message('ai_assistant_financial.totals', ['amount' => $evidence['totals']['total_amount'] ?? trans_message('ai_assistant_financial.unknown'),
            'direct' => $evidence['totals']['direct_costs'] ?? trans_message('ai_assistant_financial.unknown'), 'overhead' => $evidence['totals']['overhead_amount'] ?? trans_message('ai_assistant_financial.unknown'),
            'profit' => $evidence['totals']['profit_amount'] ?? trans_message('ai_assistant_financial.unknown')]);
        if (($evidence['totals_validation_status'] ?? $evidence['validation_status'] ?? 'unverified') !== 'verified') {
            $lines[] = trans_message('ai_assistant_financial.totals_mismatch');
        }
        if (preg_match('/ндс/iu', $query)) {
            $lines[] = $evidence['stored_totals']['total_amount_with_vat'] === null
                ? trans_message('ai_assistant_financial.vat_unknown')
                : trans_message('ai_assistant_financial.vat_stored', ['amount' => $evidence['stored_totals']['total_amount_with_vat']]);
        }
        $filter = $evidence['selection']['position_filter'] ?? [];
        $numbers = $evidence['selection']['position_numbers'] ?? [];
        if ($filter !== [] || $numbers !== [] || preg_match('/(?:позиц|какие|конкрет|подробнее|количеств|объ[её]м)/iu', $query)) {
            $positions = $this->selectedPositions($query, $evidence);
            if ($this->requestsHighestCostPosition($query) && $positions !== []) {
                $lines[] = trans_message('ai_assistant_financial.'.(count($positions) === 1 ? 'highest_position' : 'highest_positions'));
            }
            if ($this->requestsHighestCostPosition($query) && $positions === []) {
                $lines[] = trans_message('ai_assistant_financial.highest_position_unverified');
            }
            if ($filter !== [] || $numbers !== []) {
                $selectedIds = array_fill_keys(array_column($positions, 'id'), true);
                $allPositions = array_column($evidence['positions'], null, 'id');
                $selectedTotal = '0.00';
                $selectedMissing = false;
                foreach ($positions as $position) {
                    if ($position['excluded']) {
                        continue;
                    }
                    $parent = $position['parent_work_id'];
                    $seen = [];
                    $covered = false;
                    while ($parent !== null && isset($allPositions[$parent]) && ! isset($seen[$parent])) {
                        $seen[$parent] = true;
                        if (isset($selectedIds[$parent])) {
                            $covered = true;
                            break;
                        }
                        $parent = $allPositions[$parent]['parent_work_id'];
                    }
                    if (! $covered) {
                        $selectedMissing = $selectedMissing || $position['total_amount'] === null;
                        $selectedTotal = FinanceDecimal::add($selectedTotal, $position['total_amount'] ?? '0');
                    }
                }
                if ($positions !== []) {
                    $lines[] = trans_message('ai_assistant_financial.selected_total', ['filter' => $this->markdownText(implode(', ', array_merge($filter, $numbers))),
                        'amount' => $selectedMissing ? trans_message('ai_assistant_financial.unknown') : FinanceDecimal::value($selectedTotal)]);
                }
            }
            if ($positions === [] && ! $this->requestsHighestCostPosition($query)) {
                $lines[] = trans_message('ai_assistant_financial.positions_not_found');
            }
            foreach (array_slice($positions, 0, 50) as $position) {
                $lines[] = trans_message('ai_assistant_financial.position', ['number' => $this->markdownText($position['position_number']), 'name' => $this->markdownText($position['name']),
                    'quantity' => $position['quantity'], 'unit' => $this->markdownText($position['unit'] ?? ''), 'amount' => $position['total_amount'] ?? trans_message('ai_assistant_financial.unknown'),
                    'direct' => $position['direct_costs'] ?? trans_message('ai_assistant_financial.unknown')])
                    .' '.trans_message('ai_assistant_financial.'.($position['excluded'] ? 'excluded' : ($position['included_in_total'] ? 'included' : 'child')));
            }
            if (count($positions) > 50) {
                $lines[] = trans_message('ai_assistant_financial.positions_limited', ['count' => count($positions)]);
            }
        }
        $lines[] = trans_message('ai_assistant_financial.fetched_at', ['time' => $this->markdownText($evidence['fetched_at'])]);

        return implode("\n", $lines);
    }

    private function selectedPositions(string $query, array $evidence): array
    {
        if ($this->requestsHighestCostPosition($query)) {
            $highestIds = array_fill_keys(array_map('strval', $evidence['display_position_ids']
                ?? array_column($this->highestCostPositions($evidence['positions']) ?? [], 'id')), true);

            return array_values(array_filter($evidence['positions'], static fn (array $position): bool => isset($highestIds[(string) $position['id']])));
        }

        $filter = $evidence['selection']['position_filter'] ?? [];
        $numbers = $evidence['selection']['position_numbers'] ?? [];
        if ($filter === [] && $numbers === [] && ! preg_match('/(?:позиц|какие|конкрет|подробнее|количеств|объ[её]м)/iu', $query)) {
            return [];
        }
        if (preg_match('/позиц[а-яё]*\s*(?:№\s*)?(\d+(?:\.\d+)*)/iu', $query, $match)) {
            $numbers = [$match[1]];
        }

        return array_values(array_filter($evidence['positions'], static function (array $position) use ($filter, $numbers): bool {
            if ($numbers !== [] && ! in_array($position['position_number'], $numbers, true)) {
                return false;
            }
            foreach ($filter as $term) {
                if (! str_contains(mb_strtolower($position['name']), $term)) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function requestsHighestCostPosition(string $query): bool
    {
        return preg_match('/(?<![\pL\pN])сам[а-яё]*\s+дорог[а-яё]*(?![\pL\pN])/iu', $query) === 1;
    }

    private function highestCostPositions(array $positions): ?array
    {
        $candidates = array_values(array_filter($positions, static fn (array $position): bool => ($position['included_in_total'] ?? false) === true));
        if ($candidates === []) {
            return [];
        }

        $highestAmount = null;
        $highest = [];
        foreach ($candidates as $position) {
            $amount = $position['total_amount'] ?? null;
            if (! is_string($amount) || preg_match('/^-?\d+(?:\.\d{1,2})?$/D', $amount) !== 1) {
                return null;
            }
            if ($highestAmount === null || FinanceDecimal::compare($amount, $highestAmount) > 0) {
                $highestAmount = $amount;
                $highest = [$position];
            } elseif (FinanceDecimal::compare($amount, $highestAmount) === 0) {
                $highest[] = $position;
            }
        }

        usort($highest, static fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);

        return $highest;
    }

    private function markdownText(string $value): string
    {
        $value = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value) ?? '';
        $escapes = ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;'];
        foreach (str_split('!"#$%\'()*+,-./:;=?@[\\]^_`{|}~') as $character) {
            $escapes[$character] = '\\'.$character;
        }

        return strtr($value, $escapes);
    }

    private function positionFilter(string $query, array $positions): array
    {
        if (preg_match('/(?:позиц[а-яё]*|работ[а-яё]*|ресурс[а-яё]*)\s+(?:по|про|с|для)\s+[«"“]?([\pL][\pL\-]*)/iu', $query, $match)) {
            return [$this->stem(mb_strtolower($match[1]))];
        }
        preg_match_all('/[а-яё]{4,}/iu', $query, $matches);
        $terms = [];
        $stop = '/^(?:смет|позиц|работ|ресурс|стоим|сумм|скольк|каки|какой|конкрет|ден[еь]г|общ|итог|прям|затрат|прибыл|накладн|объ[её]м|колич|текущ|подроб|покаж|теперь|нужн|рубл|номер|выбер|данн|назван|друг)/u';
        foreach ($matches[0] as $word) {
            $term = $this->stem(mb_strtolower($word));
            if (mb_strlen($term) < 4 || preg_match($stop, $term)) {
                continue;
            }
            foreach ($positions as $position) {
                if (str_contains(mb_strtolower($position['name']), $term)) {
                    $terms[] = $term;
                    break;
                }
            }
        }

        return array_values(array_unique($terms));
    }

    private function stem(string $word): string
    {
        return preg_replace('/(?:ами|ями|ого|ему|ов|ев|ой|ий|ая|ое|ые|а|я|ы|и|у|ю|е)$/u', '', $word) ?? $word;
    }
}
