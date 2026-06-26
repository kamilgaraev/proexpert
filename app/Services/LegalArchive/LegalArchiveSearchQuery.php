<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

final class LegalArchiveSearchQuery
{
    private const SEARCH_COLUMNS = [
        'title',
        'document_number',
        'counterparty_name',
        'description',
    ];

    public static function sanitize(?string $term): ?string
    {
        if ($term === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($term));

        if ($normalized === null || $normalized === '') {
            return null;
        }

        return mb_substr($normalized, 0, 160);
    }

    public static function postgresExpression(): string
    {
        return self::searchVectorExpression() . " @@ plainto_tsquery('russian', ?)";
    }

    public static function searchVectorExpression(): string
    {
        return "to_tsvector('russian', coalesce(title, '') || ' ' || coalesce(document_number, '') || " .
            "' ' || coalesce(counterparty_name, '') || ' ' || coalesce(description, ''))";
    }

    public static function columns(): array
    {
        return self::SEARCH_COLUMNS;
    }
}
