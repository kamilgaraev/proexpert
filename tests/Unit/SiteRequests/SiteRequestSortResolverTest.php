<?php

declare(strict_types=1);

namespace Tests\Unit\SiteRequests;

use App\BusinessModules\Features\SiteRequests\Services\SiteRequestSortResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SiteRequestSortResolverTest extends TestCase
{
    #[DataProvider('sortColumns')]
    public function test_it_resolves_api_sort_fields_to_database_columns(string $requested, string $expected): void
    {
        self::assertSame($expected, (new SiteRequestSortResolver)->column($requested));
    }

    public static function sortColumns(): array
    {
        return [
            'deadline alias' => ['deadline', 'required_date'],
            'created date' => ['created_at', 'created_at'],
            'updated date' => ['updated_at', 'updated_at'],
            'priority' => ['priority', 'priority'],
            'unknown field' => ['unknown', 'created_at'],
        ];
    }

    #[DataProvider('sortDirections')]
    public function test_it_allows_only_supported_sort_directions(string $requested, string $expected): void
    {
        self::assertSame($expected, (new SiteRequestSortResolver)->direction($requested));
    }

    public static function sortDirections(): array
    {
        return [
            'ascending' => ['asc', 'asc'],
            'descending' => ['desc', 'desc'],
            'unsupported direction' => ['sideways', 'desc'],
        ];
    }
}
