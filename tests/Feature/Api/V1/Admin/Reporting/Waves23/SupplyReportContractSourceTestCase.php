<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting\Waves23;

use PHPUnit\Framework\TestCase;

abstract class SupplyReportContractSourceTestCase extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 7);
    }

    protected function assertContract(
        string $provider,
        string $query,
        string $materializer,
        string $resourceKind,
        ?string $visibilityGate = null,
        ?string $drillDown = null,
    ): void {
        $providerSource = $this->source($provider);
        $querySource = $this->source($query);
        $materializerSource = $this->source($materializer);
        $rowsSource = $this->source('app/Support/Reporting/EloquentOwnerReportRows.php');

        self::assertStringContainsString('implements ReportDataProvider', $providerSource);
        self::assertStringContainsString('ReportRowQuery', $querySource);
        $drillDownSource = $drillDown === null ? $querySource : $this->source($drillDown);
        self::assertStringContainsString('ReportDrillDownProvider', $drillDownSource);
        self::assertStringContainsString("sourceResourceKind: '{$resourceKind}'", $drillDownSource);
        if ($visibilityGate !== null) {
            self::assertStringContainsString($visibilityGate, $drillDownSource);
        }
        self::assertStringContainsString("->where('snapshot_id', \$snapshot->id)", $rowsSource);
        self::assertStringContainsString('source_hash', $materializerSource);
        self::assertStringContainsString('formula_version', $materializerSource);
        self::assertStringNotContainsString('->offset(', $rowsSource);
        self::assertStringContainsString('public function cursor(', $rowsSource);
    }

    private function source(string $path): string
    {
        $source = file_get_contents($this->root.'/'.str_replace('/', DIRECTORY_SEPARATOR, $path));
        self::assertIsString($source, $path);

        return $source;
    }
}
