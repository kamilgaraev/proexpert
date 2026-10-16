<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Publication\EloquentReportPublicationFeatureStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Publication\EloquentReportPublicationRegistry;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReportPublicationBatchReadTest extends TestCase
{
    public function test_composite_manifest_rereads_empty_database_catalog_without_per_builtin_queries(): void
    {
        $registry = app(ReportDefinitionRegistry::class);
        self::assertNotEmpty($registry->publishedCodes());
        $previous = null;
        DB::enableQueryLog();
        try {
            foreach ([1, 2] as $read) {
                DB::flushQueryLog();
                $digest = $registry->manifestSha256()->value;
                $publicationQueries = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'report_publications'));
                self::assertCount(2, $publicationQueries);
                if ($previous !== null) {
                    self::assertSame($previous, $digest);
                }
                $previous = $digest;
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_missing_codes_use_one_query_per_store_and_reread_on_the_next_call(): void
    {
        $publications = app(EloquentReportPublicationRegistry::class);
        $features = app(EloquentReportPublicationFeatureStore::class);
        $codes = array_map(static fn (int $number): string => 'missing_batch_report_'.$number, range(1, 16));
        DB::enableQueryLog();
        try {
            foreach ([1, 2] as $read) {
                DB::flushQueryLog();
                self::assertSame([], $publications->currentMany($codes));
                self::assertSame([], $features->currentMany($codes));
                self::assertCount(2, DB::getQueryLog());
            }
            DB::flushQueryLog();
            self::assertSame([], $publications->currentMany([]));
            self::assertSame([], $features->currentMany([]));
            self::assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_bulk_stores_reject_invalid_codes_before_querying(): void
    {
        foreach ([app(EloquentReportPublicationRegistry::class), app(EloquentReportPublicationFeatureStore::class)] as $store) {
            DB::enableQueryLog();
            try {
                DB::flushQueryLog();
                try {
                    $store->currentMany(['valid_report', 'invalid/code']);
                    self::fail('Invalid report codes must fail before the bulk read.');
                } catch (\InvalidArgumentException) {
                    self::assertSame([], DB::getQueryLog());
                }
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        }
    }
}
