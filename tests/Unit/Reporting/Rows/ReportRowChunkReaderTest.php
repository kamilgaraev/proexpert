<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Rows;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Rows\ReportRowChunkReader;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\FakeReportRowQuery;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\Support\Reporting\ReportRunBuilder;

final class ReportRowChunkReaderTest extends TestCase
{
    private ReportRowChunkReader $reader;
    private Sha256Hash $queryHash;
    private ReportWindowSort $sort;

    protected function setUp(): void
    {
        $this->reader = new ReportRowChunkReader();
        $this->queryHash = new Sha256Hash(str_repeat('b', 64));
        $this->sort = new ReportWindowSort('name', ReportSortDirection::ASC);
    }

    public function test_single_row_iterator_becomes_one_non_empty_chunk(): void
    {
        $query = $this->query([$this->validRowEnvelope('row-1')]);

        $chunks = iterator_to_array($this->reader->read(
            (new ReportExecutionContextBuilder())->build(),
            (new ReportRunBuilder())->ready()->resultMetadata->snapshot,
            $this->queryHash,
            $this->sort,
            5000,
            $query,
        ));

        self::assertCount(1, $chunks);
        self::assertCount(1, $chunks[0]->rows);
        self::assertSame('row-1', $chunks[0]->rows[0]->rowKey);
        self::assertSame([['name' => 'Строка row-1']], $chunks[0]->values());
        self::assertCount(1, $query->cursorCalls());
    }

    public function test_empty_iterator_produces_no_chunks(): void
    {
        $query = $this->query([]);

        $chunks = iterator_to_array($this->reader->read(
            (new ReportExecutionContextBuilder())->build(),
            (new ReportRunBuilder())->ready()->resultMetadata->snapshot,
            $this->queryHash,
            $this->sort,
            3,
            $query,
        ));

        self::assertSame([], $chunks);
        self::assertCount(1, $query->cursorCalls());
    }

    public function test_generated_chunks_are_non_empty_and_respect_requested_bound(): void
    {
        $rows = [];
        for ($index = 1; $index <= 7; ++$index) {
            $rows[] = $this->validRowEnvelope('row-'.$index);
        }

        $chunks = iterator_to_array($this->reader->read(
            (new ReportExecutionContextBuilder())->build(),
            (new ReportRunBuilder())->ready()->resultMetadata->snapshot,
            $this->queryHash,
            $this->sort,
            3,
            $this->query($rows),
        ));

        self::assertSame([3, 3, 1], array_map(static fn ($chunk): int => count($chunk->rows), $chunks));
        foreach ($chunks as $chunk) {
            self::assertNotEmpty($chunk->rows);
            self::assertLessThanOrEqual(3, count($chunk->rows));
        }
    }

    #[DataProvider('malformedProviderRows')]
    public function test_rejects_malformed_provider_items(mixed $item): void
    {
        $this->expectContractFailure(function () use ($item): void {
            iterator_to_array($this->reader->read(
                (new ReportExecutionContextBuilder())->build(),
                (new ReportRunBuilder())->ready()->resultMetadata->snapshot,
                $this->queryHash,
                $this->sort,
                5000,
                $this->query([$item]),
            ));
        });
    }

    public static function malformedProviderRows(): iterable
    {
        yield 'empty provider list' => [[]];
        yield 'ordinary row without identity' => [['row_key' => 'row-1', 'values' => ['name' => 'Строка']]];
        yield 'extra envelope field' => [[
            'row_key' => 'row-1',
            'values' => ['name' => 'Строка'],
            'snapshot_id' => 'snapshot',
            'query_hash' => str_repeat('b', 64),
            'source_hash' => str_repeat('c', 64),
            'extra' => true,
        ]];
    }

    public function test_rejects_provider_output_that_looks_like_an_oversized_chunk(): void
    {
        $rows = array_fill(0, 5001, $this->validRowEnvelope());

        $this->expectContractFailure(function () use ($rows): void {
            iterator_to_array($this->reader->read(
                (new ReportExecutionContextBuilder())->build(),
                (new ReportRunBuilder())->ready()->resultMetadata->snapshot,
                $this->queryHash,
                $this->sort,
                5000,
                $this->query([$rows]),
            ));
        });
    }

    #[DataProvider('identityDrift')]
    public function test_rejects_identity_drift(string $field, string $value): void
    {
        $row = $this->validRowEnvelope();
        $row[$field] = $value;

        $this->expectContractFailure(function () use ($row): void {
            iterator_to_array($this->reader->read(
                (new ReportExecutionContextBuilder())->build(),
                (new ReportRunBuilder())->ready()->resultMetadata->snapshot,
                $this->queryHash,
                $this->sort,
                5000,
                $this->query([$row]),
            ));
        });
    }

    public static function identityDrift(): iterable
    {
        yield 'snapshot' => ['snapshot_id', 'other-snapshot'];
        yield 'query' => ['query_hash', str_repeat('d', 64)];
        yield 'source' => ['source_hash', str_repeat('e', 64)];
    }

    private function validRowEnvelope(string $rowKey = 'row-1'): array
    {
        return [
            'row_key' => $rowKey,
            'values' => ['name' => 'Строка '.$rowKey],
            'snapshot_id' => 'snapshot',
            'query_hash' => $this->queryHash->value,
            'source_hash' => str_repeat('c', 64),
        ];
    }

    private function query(iterable $rows): FakeReportRowQuery
    {
        return new FakeReportRowQuery(
            new ReportPage(
                [],
                [],
                ReportFreshnessStatus::FRESH,
                new ReportQuality(
                    ReportQualityStatus::COMPLETE,
                    null,
                    [],
                    0,
                    ReportReconciliationStatus::MATCHED,
                    [],
                    [],
                ),
                null,
                100,
                false,
                $this->sort,
            ),
            $rows,
        );
    }

    private function expectContractFailure(callable $callback): void
    {
        try {
            $callback();
            self::fail('Ожидалось отклонение нарушения row-stream контракта.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_INTERNAL_ERROR, $exception->errorCode);
        }
    }
}
