<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Exports;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportArtifactStream;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportLimits;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocument;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocumentRenderer;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfRenderBudget;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Application\Rows\ReportCursorRow;
use App\BusinessModules\Core\Reporting\Application\Rows\ReportRowChunk;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotSeal;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportRunBuilder;
use Throwable;

abstract class ReportExportRendererTestCase extends TestCase
{
    /**
     * @param list<array<string, mixed>> $rowSchema
     * @return array{ReportRunExportSource, PublishedReportDefinition}
     */
    protected function source(
        int $rowCount,
        array $totals = [],
        array $rowSchema = [
            ['id' => 'name', 'labels' => ['ru-RU' => 'Название', 'en-US' => 'Name']],
            ['id' => 'amount', 'labels' => ['ru-RU' => 'Сумма', 'en-US' => 'Amount']],
            ['id' => 'date', 'labels' => ['ru-RU' => 'Дата', 'en-US' => 'Date']],
        ],
        bool $official = false,
        string $rendererVersion = 'renderer-1',
        array $formats = ['csv', 'xlsx', 'pdf'],
    ): array {
        $definitionHash = new Sha256Hash(str_repeat('a', 64));
        $outputClassification = new ReportOutputClassification(
            ReportDataClassification::STANDARD,
            ['amount'],
            ['name'],
            false,
            true,
            true,
        );
        $definition = (new ReportDefinitionBuilder())
            ->definitionHash($definitionHash)
            ->contractVersion('contract-1')
            ->formulaVersion('formula-1')
            ->sourceSchemaVersion('schema-1')
            ->rendererVersion($rendererVersion)
            ->columns($rowSchema)
            ->formats($formats)
            ->outputClassification($outputClassification)
            ->snapshotClassification(
                $official ? ReportSnapshotClassification::OFFICIAL : ReportSnapshotClassification::OPERATIONAL,
            )
            ->published();
        $scope = new ReportScope(1, [1], [], [], new DateTimeZone('Europe/Moscow'));
        $query = new ReportQuery(
            $definition->definition,
            $scope,
            new ReportFilterSet([]),
            [],
            new DateTimeImmutable('2026-07-29T10:00:00+03:00'),
            'ru-RU',
        );
        $sourceHash = new Sha256Hash(str_repeat('c', 64));
        $generatedAt = new DateTimeImmutable('2026-07-29T07:00:00.000000Z');
        $seal = $official ? new ReportSnapshotSeal(
            'report-seal-key',
            'ed25519-sha256',
            new Sha256Hash(str_repeat('d', 64)),
            rtrim(strtr(base64_encode(str_repeat("\0", 64)), '+/', '-_'), '='),
            new DateTimeImmutable('2026-07-29T07:01:00.000000Z'),
        ) : null;
        $snapshot = new ReportSnapshotRef(
            'materialized',
            'snapshot-1',
            $scope,
            $definitionHash,
            'formula-1',
            $sourceHash,
            $generatedAt,
            new DateTimeImmutable('2026-07-29T08:00:00.000000Z'),
            ['ledger' => 'wm-1'],
            $official ? ReportSnapshotClassification::OFFICIAL : ReportSnapshotClassification::OPERATIONAL,
            $seal,
        );
        $quality = new ReportQuality(
            ReportQualityStatus::COMPLETE,
            null,
            [],
            0,
            ReportReconciliationStatus::MATCHED,
            [],
            [],
        );
        $provenance = new ReportProvenance(
            'system',
            [new ReportSourceRef('system', 'table', 'snapshot_1', 'schema_1', 'wm_1', $rowCount, $sourceHash)],
            $sourceHash,
            null,
        );
        $metadata = new ReportResultMetadata($snapshot, $rowCount, $generatedAt, $snapshot->staleAt);
        $result = new ReportResult(
            $metadata,
            $totals,
            ReportFreshnessStatus::FRESH,
            $quality,
            $provenance,
            $rowSchema,
            [],
        );
        $run = (new ReportRunBuilder())
            ->definitionHash($definitionHash)
            ->contractVersion('contract-1')
            ->formulaVersion('formula-1')
            ->sourceSchemaVersion('schema-1')
            ->rendererVersion($rendererVersion)
            ->queryHash($query->queryHash)
            ->sourceHash($sourceHash)
            ->rowCount($rowCount)
            ->resultMetadata($metadata)
            ->totals($totals)
            ->freshness(ReportFreshnessStatus::FRESH)
            ->quality($quality)
            ->provenance($provenance)
            ->updatedAt($generatedAt)
            ->readyAt($generatedAt)
            ->expiresAt(new DateTimeImmutable('2026-07-30T07:00:00.000000Z'))
            ->ready();
        $projection = (new ReflectionClass(ReportRunExportSource::class))
            ->getMethod('resultProjection')
            ->invoke(null, $result);
        $resultHash = new Sha256Hash(hash('sha256', CanonicalJson::encode($projection)));

        return [
            new ReportRunExportSource(
                $run,
                $query,
                $result,
                $resultHash,
                $snapshot,
                ReportDataClassification::STANDARD,
                $outputClassification,
                'contract-1',
                'formula-1',
                'schema-1',
                $rendererVersion,
            ),
            $definition,
        ];
    }

    protected function data(
        string $format,
        array $columns = ['name', 'amount', 'date'],
        string $locale = 'ru-RU',
    ): CreateReportExportData {
        return new CreateReportExportData(
            $format,
            $columns,
            new ReportWindowSort('name', ReportSortDirection::ASC),
            $locale,
            new DateTimeZone('Europe/Moscow'),
        );
    }

    /** @param list<array<string, mixed>> $values */
    protected function chunk(ReportRunExportSource $source, array $values, int $offset = 0): ReportRowChunk
    {
        $rows = [];
        foreach ($values as $index => $value) {
            $rows[] = new ReportCursorRow(
                'row-'.($offset + $index),
                $value,
                $source->snapshot->id,
                $source->run->queryHash,
                $source->snapshot->sourceHash,
            );
        }

        return new ReportRowChunk($rows);
    }

    /** @return iterable<ReportRowChunk> */
    protected function generatedChunks(
        ReportRunExportSource $source,
        int $rowCount,
        int $chunkSize,
        ?callable $onChunk = null,
    ): iterable {
        for ($offset = 0; $offset < $rowCount; $offset += $chunkSize) {
            $size = min($chunkSize, $rowCount - $offset);
            $rows = [];
            for ($index = 0; $index < $size; $index++) {
                $number = $offset + $index;
                $rows[] = [
                    'name' => 'Строка '.$number,
                    'amount' => (string) $number.'.25',
                    'date' => '2026-07-29',
                ];
            }
            if ($onChunk !== null) {
                $onChunk($size);
            }
            yield $this->chunk($source, $rows, $offset);
        }
    }

    protected function assertLimit(Throwable $exception): void
    {
        self::assertInstanceOf(ReportContractException::class, $exception);
        self::assertSame(ReportErrorCode::REPORT_EXPORT_LIMIT_EXCEEDED, $exception->errorCode);
    }
}

final class InMemoryReportArtifactStream implements ReportArtifactStream
{
    private string $bytes = '';

    private int $cancellationChecks = 0;

    public function __construct(private readonly ?int $cancelOnCheck = null)
    {
    }

    public function write(string $bytes): void
    {
        $this->bytes .= $bytes;
    }

    public function cancellationRequested(): bool
    {
        $this->cancellationChecks++;

        return $this->cancelOnCheck !== null && $this->cancellationChecks >= $this->cancelOnCheck;
    }

    public function bytes(): string
    {
        return $this->bytes;
    }
}

final class HashingReportArtifactStream implements ReportArtifactStream
{
    private mixed $hash;

    private int $size = 0;

    public function __construct()
    {
        $this->hash = hash_init('sha256');
    }

    public function write(string $bytes): void
    {
        hash_update($this->hash, $bytes);
        $this->size += strlen($bytes);
    }

    public function cancellationRequested(): bool
    {
        return false;
    }

    public function checksum(): string
    {
        return hash_final(hash_copy($this->hash));
    }

    public function size(): int
    {
        return $this->size;
    }
}

final class FakeReportPdfDocumentRenderer implements ReportPdfDocumentRenderer
{
    public ?ReportPdfDocument $document = null;

    public int $calls = 0;

    public function __construct(
        private readonly string $bytes = '%PDF-1.7 fake',
        private readonly ?Throwable $failure = null,
    ) {
    }

    public function render(ReportPdfDocument $document, ReportPdfRenderBudget $budget): string
    {
        $this->calls++;
        $this->document = $document;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->bytes;
    }
}

final class CsvReportExportRendererTest extends ReportExportRendererTestCase
{
    public function test_streams_utf8_rfc4180_with_locale_headers_and_formula_neutralization(): void
    {
        [$source] = $this->source(2, ['amount' => '13.75']);
        $stream = new InMemoryReportArtifactStream();
        $renderer = new CsvReportExportRenderer();

        $count = $renderer->render(
            $source,
            $this->data('csv', ['amount', 'name'], 'ru-RU'),
            [$this->chunk($source, [
                ['name' => '=HYPERLINK("bad")', 'amount' => '12.50', 'date' => '2026-07-29'],
                ['name' => 'Кран, "Север"', 'amount' => 1.25, 'date' => '2026-07-30'],
            ])],
            $stream,
        );

        self::assertSame(2, $count);
        self::assertSame(
            "\xEF\xBB\xBFСумма;Название\r\n"
            ."12,50;\"'=HYPERLINK(\"\"bad\"\")\"\r\n"
            ."1,25;\"Кран, \"\"Север\"\"\"\r\n"
            ."13,75;Итого\r\n",
            $stream->bytes(),
        );
    }

    public function test_checksum_is_stable_and_cancellation_is_checked_between_chunks(): void
    {
        [$source] = $this->source(2);
        $chunks = [
            $this->chunk($source, [['name' => 'A', 'amount' => '1.25', 'date' => '2026-07-29']]),
            $this->chunk($source, [['name' => 'B', 'amount' => '2.25', 'date' => '2026-07-30']], 1),
        ];
        $first = new InMemoryReportArtifactStream();
        $second = new InMemoryReportArtifactStream();
        $renderer = new CsvReportExportRenderer();
        $renderer->render($source, $this->data('csv'), $chunks, $first);
        $renderer->render($source, $this->data('csv'), $chunks, $second);

        self::assertSame(hash('sha256', $first->bytes()), hash('sha256', $second->bytes()));

        $cancelled = new InMemoryReportArtifactStream(2);
        try {
            $renderer->render($source, $this->data('csv'), $chunks, $cancelled);
            self::fail('Expected cancellation to fail closed.');
        } catch (Throwable $exception) {
            $this->assertLimit($exception);
        }
        self::assertStringContainsString("A", $cancelled->bytes());
        self::assertStringNotContainsString("B", $cancelled->bytes());
    }

    public function test_row_column_and_projected_byte_limits_are_enforced(): void
    {
        [$source] = $this->source(2);
        $stream = new InMemoryReportArtifactStream();
        $renderer = new CsvReportExportRenderer(new ReportExportLimits(1, 3, 1024, 4, 60));

        try {
            $renderer->render(
                $source,
                $this->data('csv'),
                [$this->chunk($source, [
                    ['name' => 'A', 'amount' => '1', 'date' => '2026-07-29'],
                    ['name' => 'B', 'amount' => '2', 'date' => '2026-07-30'],
                ])],
                $stream,
            );
            self::fail('Expected row limit.');
        } catch (Throwable $exception) {
            $this->assertLimit($exception);
        }

        $tiny = new CsvReportExportRenderer(new ReportExportLimits(2, 3, 4, 4, 60));
        try {
            $tiny->render($source, $this->data('csv'), [], new InMemoryReportArtifactStream());
            self::fail('Expected byte limit.');
        } catch (Throwable $exception) {
            $this->assertLimit($exception);
        }
    }
}
