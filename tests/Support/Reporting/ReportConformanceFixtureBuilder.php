<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportConformanceFixture;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownCell;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;

final class ReportConformanceFixtureBuilder
{
    private Sha256Hash $fixtureHash;

    private int $expectedRowCount = 2;

    private ReportWindowSort $sort;

    private int $pageLimit = 2;

    private int $cursorChunkSize = 100;

    private ReportDrillDownRequest $drillDown;

    private Sha256Hash $expectedTotalsHash;

    private ReportDrillDownCell $drillDownCell;

    private Sha256Hash $expectedDrillDownHash;

    public function __construct()
    {
        $this->fixtureHash = new Sha256Hash(str_repeat('f', 64));
        $this->sort = new ReportWindowSort('name', ReportSortDirection::ASC);
        $this->drillDown = new ReportDrillDownRequest('signed.opaque.token', null, 25);
        $this->expectedTotalsHash = new Sha256Hash(
            hash('sha256', '{"amount":"30.00"}'),
        );
        $this->drillDownCell = new ReportDrillDownCell('row-1', 'name');
        $this->expectedDrillDownHash = new Sha256Hash(
            'c621ab66913de009c148301a72538f1e3c8ae7899452800387e8eb4cb6448641',
        );
    }

    public function fixtureHash(Sha256Hash $value): self
    {
        $this->fixtureHash = $value;

        return $this;
    }

    public function expectedRowCount(int $value): self
    {
        $this->expectedRowCount = $value;

        return $this;
    }

    public function sort(ReportWindowSort $value): self
    {
        $this->sort = $value;

        return $this;
    }

    public function pageLimit(int $value): self
    {
        $this->pageLimit = $value;

        return $this;
    }

    public function cursorChunkSize(int $value): self
    {
        $this->cursorChunkSize = $value;

        return $this;
    }

    public function drillDown(ReportDrillDownRequest $value): self
    {
        $this->drillDown = $value;

        return $this;
    }

    public function expectedTotalsHash(Sha256Hash $value): self
    {
        $this->expectedTotalsHash = $value;

        return $this;
    }

    public function drillDownCell(ReportDrillDownCell $value): self
    {
        $this->drillDownCell = $value;

        return $this;
    }

    public function expectedDrillDownHash(Sha256Hash $value): self
    {
        $this->expectedDrillDownHash = $value;

        return $this;
    }

    public function build(): ReportConformanceFixture
    {
        return new ReportConformanceFixture(
            $this->fixtureHash,
            $this->expectedRowCount,
            $this->sort,
            $this->pageLimit,
            $this->cursorChunkSize,
            $this->drillDown,
            $this->expectedTotalsHash,
            $this->drillDownCell,
            $this->expectedDrillDownHash,
        );
    }
}
