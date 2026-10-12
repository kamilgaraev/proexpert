<?php

declare(strict_types=1);

namespace App\Services\Contract;

final class ContractRelatedContentDiff
{
    public function compare(array $before, array $after): array
    {
        $left = $this->parties($before['parties'] ?? []);
        $right = $this->parties($after['parties'] ?? []);
        $labels = [...trans('contracts.proposal_party_fields'), ...ContractPartyRequisites::LABELS];
        $changes = [];
        foreach (array_unique([...array_keys($left), ...array_keys($right)]) as $key) {
            $previous = $left[$key] ?? []; $next = $right[$key] ?? [];
            $name = $next['legal_name'] ?? $next['name'] ?? $previous['legal_name'] ?? $previous['name'];
            foreach ($labels as $field => $label) {
                $old = $previous[$field] ?? null; $new = $next[$field] ?? null;
                if ($old === $new) continue;
                $changes[] = ['kind' => 'party', 'key' => $key.':'.$field,
                    'label' => trans_message('contracts.proposal_party_change', ['name' => $name, 'field' => $label]),
                    'before_display' => $this->value($field, $old), 'after_display' => $this->value($field, $new)];
            }
        }
        $old = $this->attachments($before['attachments'] ?? []);
        $new = $this->attachments($after['attachments'] ?? []);
        if ($old !== $new) $changes[] = ['kind' => 'attachments', 'key' => 'attachments', 'label' => trans_message('contracts.proposal_attachments'),
            'before_display' => $old ?: trans_message('contracts.proposal_no_attachments'), 'after_display' => $new ?: trans_message('contracts.proposal_no_attachments')];

        return $changes;
    }

    private function parties(array $parties): array
    {
        $result = [];
        foreach ($parties as $index => $party) $result[$party['party_key'] ?? $party['side'] ?? (string) $index] = $party;

        return $result;
    }

    private function value(string $field, mixed $value): string
    {
        if ($value === null || $value === '') return trans_message('contracts.proposal_value_missing');
        if (in_array($field, ['person_type', 'authority_kind'], true)) return trans_message('contracts.proposal_party_values.'.$value);

        return (string) $value;
    }

    private function attachments(array $attachments): string
    {
        return implode("\n", array_map(static fn (array $asset): string => trans_message('contracts.proposal_attachment',
            ['name' => $asset['name'], 'size' => $asset['size'], 'hash' => $asset['sha256']]), $attachments));
    }
}
