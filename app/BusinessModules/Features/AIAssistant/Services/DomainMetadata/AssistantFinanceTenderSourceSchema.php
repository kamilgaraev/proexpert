<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\DomainMetadata;

use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class AssistantFinanceTenderSourceSchema
{
    public const FIELD = 'assistant_public_schema_revision';
    private static ?array $revisions = null;

    public static function revisions(): array
    {
        if (self::$revisions !== null) { return self::$revisions; }
        $result = [];
        $public = AssistantFinanceTenderMetadata::publicFields();
        foreach (AssistantFinanceTenderMetadata::inventory() as $type => $definition) {
            if (array_diff($definition['fields'], $public[$type] ?? []) === []) { continue; }
            $fields = $public[$type] ?? [];
            sort($fields, SORT_STRING);
            $result[$type] = AssistantSourceReferenceIdentity::key(['schema' => 'finance_tender_public_v1', 'entity_type' => $type, 'fields' => $fields]);
        }

        return self::$revisions = $result;
    }

    public static function revision(string $type): ?string { return self::revisions()[$type] ?? null; }

    public static function allowsSource(array $source): bool
    {
        $revision = self::revision((string) ($source['entity_type'] ?? $source['entityType'] ?? ''));
        if ($revision === null) { return true; }
        $metadata = $source['metadata'] ?? [];
        $value = $source[self::FIELD] ?? (is_array($metadata) ? ($metadata[self::FIELD] ?? null) : null);

        return is_string($value) && hash_equals($revision, $value);
    }

    public static function allowsReference(string $type, array $reference): bool
    {
        $revision = self::revision($type);
        if ($revision === null) { return true; }
        if (($reference['content_scope'] ?? null) === 'structured') {
            $fields = $reference['checked_fields'] ?? null;
            if (! is_array($fields) || array_filter($fields, static fn ($field): bool => ! is_string($field)) !== []) { return false; }
            return is_array($fields) && $fields !== [] && array_diff($fields, AssistantFinanceTenderMetadata::publicFields()[$type]) === [];
        }
        $metadata = $reference['metadata'] ?? [];
        $value = $reference[self::FIELD] ?? (is_array($metadata) ? ($metadata[self::FIELD] ?? null) : null);

        return is_string($value) && hash_equals($revision, $value);
    }

    public static function apply(Builder|QueryBuilder $query, string $table): void
    {
        $revisions = self::revisions();
        $query->where(static function (Builder|QueryBuilder $scope) use ($table, $revisions): void {
            $scope->whereNotIn($table.'.entity_type', array_keys($revisions));
            foreach ($revisions as $type => $revision) {
                $scope->orWhere(static fn (Builder|QueryBuilder $branch) => $branch->where($table.'.entity_type', $type)
                    ->where($table.'.metadata->'.self::FIELD, $revision));
            }
        });
    }
}
