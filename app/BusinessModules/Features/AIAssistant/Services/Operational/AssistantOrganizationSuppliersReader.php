<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantOrganizationSuppliersReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_organization_supplier_roster_projection';

    public static function hasIntent(string $query): bool
    {
        return preg_match('/поставщик/iu', $query) === 1 && preg_match('/справочник|нашей\s+организац|всей\s+организац/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query) && preg_match('/создай|удали|измени|оплат|плат[её]ж|сч[её]т|договор|поставк|проект|объект|расход|цен|стоим|контакт|телефон|почт/iu', $query) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        $query = $this->access->entityQuery($actor, $organizationId, 'supplier');
        if ($query === null) { throw new AuthorizationException; }
        $total = (clone $query)->count();
        $active = (clone $query)->where('is_active', true)->count();
        $examples = (clone $query)->orderBy('name')->orderBy('id')->limit(20)->select(['id', 'name', 'is_active'])->get()
            ->map(static fn ($row): array => ['id' => (int) $row->id, 'name' => $row->name, 'active' => (bool) $row->is_active])->all();
        return ['total' => $total, 'active' => $active, 'examples' => $examples];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.supplier_scope', ['date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.supplier_counts', ['total' => $data['total'], 'active' => $data['active']])];
        foreach ($data['examples'] as $row) {
            $lines[] = '- #'.$row['id'].' '.self::text($row['name']).'; '.trans_message('ai_assistant_operational.supplier_'.($row['active'] ? 'active' : 'inactive'));
        }
        $lines[] = trans_message('ai_assistant_operational.supplier_navigation');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');
        return implode("\n", $lines);
    }
}
