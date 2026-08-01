<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting\Reporting;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationFence;
use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationSubject;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportExactManyAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportArtifactStream;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportLimits;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocument;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocumentBuilder;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocumentRenderer;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\PdfReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\ReportExportRendererRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Budgeting\BudgetPlanFactExportEvidenceFixture;
use Throwable;
use ZipArchive;

final class BudgetPlanFactExportEvidenceTest extends TestCase
{
    public function test_sealed_plan_fact_snapshot_renders_stable_canonical_csv_and_xlsx(): void
    {
        [$source, $definition, $chunks] = BudgetPlanFactExportEvidenceFixture::sealedSource();
        $registry = $this->registry();

        $csv = $this->render($registry, $definition, $source, 'csv', $chunks);
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString("actual_amount;committed_amount;currency;drill;forecast_amount;group;plan_amount;risk_level;row_key;variance_amount;variance_percent\r\n", $csv);
        self::assertSame($csv, $this->render($registry, $definition, $source, 'csv', $chunks));
        self::assertSame(2, substr_count($csv, "\r\n") - 1);
        $group = CanonicalJson::encode($chunks[0]->rows[0]->values['group']);
        $drill = CanonicalJson::encode($chunks[0]->rows[0]->values['drill']);
        self::assertStringContainsString('"'.str_replace('"', '""', $group).'"', $csv);
        self::assertStringContainsString('"'.str_replace('"', '""', $drill).'"', $csv);

        $xlsx = $this->render($registry, $definition, $source, 'xlsx', $chunks);
        self::assertSame($xlsx, $this->render($registry, $definition, $source, 'xlsx', $chunks));
        $sheet = $this->sheet($xlsx);
        self::assertStringContainsString('<c r="A1" s="1" t="inlineStr"><is><t>actual_amount</t>', $sheet);
        self::assertStringContainsString('<c r="K1" s="1" t="inlineStr"><is><t>variance_percent</t>', $sheet);
        self::assertStringContainsString('<c r="I2" t="inlineStr"><is><t>plan_fact:', $sheet);
        self::assertStringContainsString('<c r="I3" t="inlineStr"><is><t>plan_fact:', $sheet);
        self::assertStringContainsString(htmlspecialchars($group, ENT_XML1 | ENT_QUOTES, 'UTF-8'), $sheet);
        self::assertStringContainsString(htmlspecialchars($drill, ENT_XML1 | ENT_QUOTES, 'UTF-8'), $sheet);
    }

    public function test_renderer_rejects_unpublished_format_and_identity_tampering_before_rows_are_read(): void
    {
        [$source, $definition] = BudgetPlanFactExportEvidenceFixture::sealedSource();
        $registry = $this->registry();
        foreach (['pdf', 'csv'] as $format) {
            $tampered = $format === 'pdf'
                ? BudgetPlanFactExportEvidenceFixture::export('pdf')
                : new \App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData(
                    'csv',
                    [...BudgetPlanFactExportEvidenceFixture::export('csv')->columns, 'unknown'],
                    BudgetPlanFactExportEvidenceFixture::export('csv')->sort,
                    'ru-RU',
                    new \DateTimeZone('UTC'),
                );
            try {
                $registry->resolve($definition, $tampered);
                self::fail('Expected unsupported export request rejection.');
            } catch (Throwable $exception) {
                $this->assertLimit($exception);
            }
        }

        $renderer = $registry->resolve($definition, BudgetPlanFactExportEvidenceFixture::export('csv'));
        $cursorReads = 0;
        $chunks = (static function () use (&$cursorReads): iterable {
            $cursorReads++;
            yield from [];
        })();
        [$tamperedSource] = BudgetPlanFactExportEvidenceFixture::sealedSource('other-renderer');
        try {
            $renderer->render($tamperedSource, BudgetPlanFactExportEvidenceFixture::export('csv'), $chunks, new BudgetPlanFactInMemoryStream);
            self::fail('Expected renderer identity rejection.');
        } catch (Throwable $exception) {
            $this->assertLimit($exception);
        }
        self::assertSame(0, $cursorReads);

        $this->expectException(ReportContractException::class);
        BudgetPlanFactExportEvidenceFixture::sealedSource(tamperDefinitionHash: true);
    }

    public function test_sealed_rows_reject_query_and_source_identity_tampering_and_neutralize_csv_formulas(): void
    {
        [$source, $definition, $chunks] = BudgetPlanFactExportEvidenceFixture::sealedSource();
        $registry = $this->registry();
        $renderer = $registry->resolve($definition, BudgetPlanFactExportEvidenceFixture::export('csv'));
        $original = $chunks[0]->rows[0];
        foreach ([
            new \App\BusinessModules\Core\Reporting\Application\Rows\ReportCursorRow(
                $original->rowKey, $original->values, $original->snapshotId, new Sha256Hash(str_repeat('d', 64)), $original->sourceHash,
            ),
            new \App\BusinessModules\Core\Reporting\Application\Rows\ReportCursorRow(
                $original->rowKey, $original->values, $original->snapshotId, $original->queryHash, new Sha256Hash(str_repeat('e', 64)),
            ),
        ] as $row) {
            try {
                $renderer->render($source, BudgetPlanFactExportEvidenceFixture::export('csv'), [new \App\BusinessModules\Core\Reporting\Application\Rows\ReportRowChunk([$row])], new BudgetPlanFactInMemoryStream);
                self::fail('Expected sealed row identity rejection.');
            } catch (Throwable $exception) {
                $this->assertLimit($exception);
            }
        }

        $formulaValues = $original->values;
        $formulaValues['risk_level'] = '=SUM(1+1)';
        $formulaRow = new \App\BusinessModules\Core\Reporting\Application\Rows\ReportCursorRow(
            $original->rowKey,
            $formulaValues,
            $original->snapshotId,
            $original->queryHash,
            $original->sourceHash,
        );
        $secondValues = $chunks[0]->rows[1]->values;
        $secondValues['risk_level'] = "quoted\";field\r\nnext";
        $second = new \App\BusinessModules\Core\Reporting\Application\Rows\ReportCursorRow(
            $chunks[0]->rows[1]->rowKey,
            $secondValues,
            $chunks[0]->rows[1]->snapshotId,
            $chunks[0]->rows[1]->queryHash,
            $chunks[0]->rows[1]->sourceHash,
        );
        $stream = new BudgetPlanFactInMemoryStream;
        $renderer->render($source, BudgetPlanFactExportEvidenceFixture::export('csv'), [new \App\BusinessModules\Core\Reporting\Application\Rows\ReportRowChunk([$formulaRow, $second])], $stream);
        self::assertStringContainsString("'=SUM(1+1)", $stream->bytes());
        self::assertStringContainsString("\"quoted\"\";field\r\nnext\"", $stream->bytes());
    }

    public function test_revoked_export_authorization_fails_closed_for_the_sealed_plan_fact_subject(): void
    {
        [$source, $definition] = BudgetPlanFactExportEvidenceFixture::sealedSource();
        $context = new ReportExecutionContext(
            new ReportActor(17, 'active', ['budgeting.plan_fact.view']),
            $source->snapshot->scope,
            new ReportVisibility(true, true, false, false, false, false, false),
            new AuthorizationDecisionContext('queue', 1, [1], [10, 20], [], new \DateTimeZone('UTC'), 'budget-plan-fact-export-evidence', null),
        );
        $subject = new ReportAuthorizationSubject(
            ReportDispatchAggregate::RUN,
            $source->run->id,
            $definition->definition,
            $source->snapshot->scope,
            $source->snapshot,
            null,
            null,
        );
        $fence = new ReportAuthorizationFence(
            $subject,
            [ReportOperation::EXPORT],
            'csv',
            new BudgetPlanFactRevokedExportAuthorizer,
            new ReportExecutionContextFactory,
        );
        try {
            $fence->assertCurrent($context);
            self::fail('Expected revoked export authorization rejection.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ReportContractException::class, $exception);
            self::assertSame(ReportErrorCode::REPORT_SCOPE_FORBIDDEN, $exception->errorCode);
        }
    }

    public function test_forged_or_revoked_official_seal_cannot_be_retrieved_for_export(): void
    {
        foreach ([
            'signature' => ['tamperSignature' => true],
            'payload' => ['tamperPayload' => true],
            'revoked key' => ['revokedKey' => true],
        ] as $name => $arguments) {
            try {
                BudgetPlanFactExportEvidenceFixture::sealedSource(...$arguments);
                self::fail("Expected {$name} seal rejection.");
            } catch (Throwable $exception) {
                self::assertInstanceOf(ReportContractException::class, $exception, $name);
                self::assertSame(ReportErrorCode::REPORT_OFFICIAL_SNAPSHOT_UNSEALED, $exception->errorCode, $name);
            }
        }
    }

    /** @param list<\App\BusinessModules\Core\Reporting\Application\Rows\ReportRowChunk> $chunks */
    private function render(ReportExportRendererRegistry $registry, mixed $definition, mixed $source, string $format, array $chunks): string
    {
        $stream = new BudgetPlanFactInMemoryStream;
        $renderer = $registry->resolve($definition, BudgetPlanFactExportEvidenceFixture::export($format));
        self::assertSame(2, $renderer->render($source, BudgetPlanFactExportEvidenceFixture::export($format), $chunks, $stream));

        return $stream->bytes();
    }

    private function registry(): ReportExportRendererRegistry
    {
        return new ReportExportRendererRegistry(
            new CsvReportExportRenderer,
            new XlsxReportExportRenderer,
            new PdfReportExportRenderer(new ReportPdfDocumentBuilder(ReportExportLimits::pdf()), new BudgetPlanFactNeverPdfRenderer, []),
        );
    }

    private function sheet(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'most-budget-plan-fact-xlsx-');
        self::assertIsString($path);
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path) === true);
        try {
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            self::assertIsString($sheet);

            return $sheet;
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    private function assertLimit(Throwable $exception): void
    {
        self::assertInstanceOf(ReportContractException::class, $exception);
        self::assertSame(ReportErrorCode::REPORT_EXPORT_LIMIT_EXCEEDED, $exception->errorCode);
    }
}

final class BudgetPlanFactInMemoryStream implements ReportArtifactStream
{
    private string $bytes = '';

    public function write(string $bytes): void
    {
        $this->bytes .= $bytes;
    }

    public function cancellationRequested(): bool
    {
        return false;
    }

    public function bytes(): string
    {
        return $this->bytes;
    }
}

final class BudgetPlanFactNeverPdfRenderer implements ReportPdfDocumentRenderer
{
    public function render(ReportPdfDocument $document, \App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfRenderBudget $budget): string
    {
        throw new LogicException('pdf_must_be_rejected_by_the_published_format_gate');
    }
}

final class BudgetPlanFactRevokedExportAuthorizer implements CurrentReportExactManyAuthorizer
{
    public function authorizeExactMany(int $actorId, \App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope $requestedScope, array $targets): array
    {
        return [];
    }
}
