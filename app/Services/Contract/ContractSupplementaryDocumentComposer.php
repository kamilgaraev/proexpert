<?php

declare(strict_types=1);

namespace App\Services\Contract;

final class ContractSupplementaryDocumentComposer
{
    public function changes(array $definitions, array $baseValues, array $values, array $entitySnapshots = []): array
    {
        $changes = [];
        $ids = array_unique([...array_keys($baseValues), ...array_keys($values)]);
        sort($ids);
        $byId = [];
        foreach ($definitions as $entry) {
            $byId[$entry['id']] = $entry;
        }
        $renderer = new ContractDocumentRenderer;
        foreach ($ids as $id) {
            $before = $baseValues[$id] ?? null;
            $after = $values[$id] ?? null;
            if ($this->same($before, $after)) {
                continue;
            }
            $entry = $byId[$id] ?? null;
            if ($entry === null) {
                continue;
            }
            $changes[] = [
                'id' => $id,
                'title' => $entry['title'] ?? $id,
                'before' => $renderer->formatValue($entry['definition'], $before, $entitySnapshots),
                'after' => $renderer->formatValue($entry['definition'], $after, $entitySnapshots),
            ];
        }

        return $changes;
    }

    public function composeHtml(array $frameDocument, array $frameDefinitions, array $frameValues, array $changes, array $entitySnapshots = []): string
    {
        $html = (new ContractDocumentRenderer)->render($frameDocument, $frameDefinitions, $frameValues, $entitySnapshots);
        $html .= '<section><h2>'.e(trans_message('contracts.supplementary_changes_heading')).'</h2><ul>';
        foreach ($changes as $change) {
            $html .= '<li><strong>'.e($change['title']).'</strong>: '
                .e(trans_message('contracts.supplementary_change_was')).' '.e((string) $change['before']).'; '
                .e(trans_message('contracts.supplementary_change_became')).' '.e((string) $change['after']).'</li>';
        }
        $html .= '</ul></section>';

        return $html;
    }

    public function contentHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function same(mixed $left, mixed $right): bool
    {
        return json_encode($left, JSON_THROW_ON_ERROR) === json_encode($right, JSON_THROW_ON_ERROR);
    }
}
