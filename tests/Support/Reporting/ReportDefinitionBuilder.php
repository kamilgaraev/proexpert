<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;

final class ReportDefinitionBuilder
{
    private string $code = 'report';
    private Sha256Hash $definitionHash;
    private string $contractVersion = '1';
    private string $formulaVersion = '1';
    private string $sourceSchemaVersion = '1';
    private string $rendererVersion = '1';
    private array $filters = [['id' => 'period']];
    private array $columns = [['id' => 'name']];
    private array $sorts = [['id' => 'name']];
    private array $formats = ['csv'];
    private ReportPermissionPolicy $permissionPolicy;
    private ReportSnapshotClassification $snapshotClassification = ReportSnapshotClassification::OPERATIONAL;
    private ReportOutputClassification $outputClassification;
    private ReportPublicationReadiness $publicationReadiness = ReportPublicationReadiness::PUBLISHED;
    private bool $supportsSubscriptions = false;

    public function __construct()
    {
        $this->definitionHash = new Sha256Hash(str_repeat('a', 64));
        $this->permissionPolicy = new ReportPermissionPolicy(['reports.view'], ['reports.export'], [], []);
        $this->outputClassification = new ReportOutputClassification(
            ReportDataClassification::STANDARD,
            [],
            [],
            false,
            false,
            false,
        );
    }

    public function code(string $value): self { $this->code = $value; return $this; }
    public function definitionHash(Sha256Hash $value): self { $this->definitionHash = $value; return $this; }
    public function contractVersion(string $value): self { $this->contractVersion = $value; return $this; }
    public function formulaVersion(string $value): self { $this->formulaVersion = $value; return $this; }
    public function sourceSchemaVersion(string $value): self { $this->sourceSchemaVersion = $value; return $this; }
    public function rendererVersion(string $value): self { $this->rendererVersion = $value; return $this; }
    public function filters(array $value): self { $this->filters = $value; return $this; }
    public function columns(array $value): self { $this->columns = $value; return $this; }
    public function sorts(array $value): self { $this->sorts = $value; return $this; }
    public function formats(array $value): self { $this->formats = $value; return $this; }
    public function permissionPolicy(ReportPermissionPolicy $value): self { $this->permissionPolicy = $value; return $this; }
    public function snapshotClassification(ReportSnapshotClassification $value): self { $this->snapshotClassification = $value; return $this; }
    public function outputClassification(ReportOutputClassification $value): self { $this->outputClassification = $value; return $this; }
    public function publicationReadiness(ReportPublicationReadiness $value): self { $this->publicationReadiness = $value; return $this; }
    public function supportsSubscriptions(bool $value): self { $this->supportsSubscriptions = $value; return $this; }

    public function payload(): ReportDefinition
    {
        return new ReportDefinition($this->code, $this->definitionHash, $this->contractVersion, $this->formulaVersion, $this->sourceSchemaVersion, $this->rendererVersion, $this->filters, $this->columns, $this->sorts, $this->formats, $this->permissionPolicy, $this->snapshotClassification, $this->outputClassification, $this->publicationReadiness, $this->supportsSubscriptions);
    }

    public function candidate(): CandidateReportDefinition
    {
        return new CandidateReportDefinition($this->publicationReadiness(ReportPublicationReadiness::CANDIDATE)->payload());
    }

    public function published(): PublishedReportDefinition
    {
        return new PublishedReportDefinition($this->publicationReadiness(ReportPublicationReadiness::PUBLISHED)->payload());
    }
}
