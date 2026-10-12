<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use Illuminate\Database\Eloquent\Builder;

final class AssistantDomainPredicate
{
    public static function supports(string $domain, string $type, mixed $predicate): bool
    {
        return match ($predicate) {
            null => true,
            'overdue' => $domain === 'schedule' && $type === 'schedule_task',
            'confirmed' => $domain === 'works' && $type === 'completed_work',
            'supplier_waiting' => $domain === 'warehouse' && $type === 'project_material_delivery',
            'unresolved' => $domain === 'quality' && $type === 'quality_defect',
            default => false,
        };
    }

    public static function apply(Builder $query, ?string $predicate): void
    {
        $table = $query->getModel()->getTable();
        match ($predicate) {
            'overdue' => $query->overdue(),
            'confirmed' => $query->where($table.'.status', 'confirmed'),
            'supplier_waiting' => $query->where($table.'.source_type', 'purchase')->whereNotIn($table.'.status', ['accepted', 'cancelled']),
            'unresolved' => $query->whereNotIn($table.'.status', ['resolved', 'cancelled']),
            default => null,
        };
    }
}
