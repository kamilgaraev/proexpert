<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportRunData;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ReportSubscriptionExecutionInput
{
    public function __construct(public string $reportCode, public ReportFilterSet $filters, public array $comparison, public string $locale, public string $savedViewId, public string $format, public array $columns, public ReportWindowSort $sort, public DateTimeZone $timezone, public array $periodPolicy, public string $contractVersion, public Sha256Hash $definitionHash)
    {
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $savedViewId) !== 1 || !in_array($format, ['csv', 'xlsx', 'pdf'], true) || $columns === [] || count($columns) !== count(array_unique($columns)) || preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', $locale) !== 1 || $periodPolicy === [] || trim($contractVersion) === '') throw new InvalidArgumentException('report_subscription_execution_input_invalid');
    }
    public function runData(DateTimeImmutable $asOf): CreateReportRunData { return new CreateReportRunData($this->reportCode, $this->filters, $this->comparison, $asOf, $this->locale, $this->savedViewId); }
    public function exportData(): CreateReportExportData { return new CreateReportExportData($this->format, $this->columns, $this->sort, $this->locale, $this->timezone); }
    public function canonicalBytes(): string { return CanonicalJson::encode(['report_code'=>$this->reportCode,'filters'=>$this->filters->values,'comparison'=>$this->comparison,'locale'=>$this->locale,'saved_view_id'=>$this->savedViewId,'format'=>$this->format,'columns'=>$this->columns,'sort'=>['field'=>$this->sort->field,'direction'=>$this->sort->direction->value],'timezone'=>$this->timezone->getName(),'period_policy'=>$this->periodPolicy,'contract_version'=>$this->contractVersion,'definition_sha256'=>$this->definitionHash->value]); }
    public function digest(): Sha256Hash { return new Sha256Hash(hash('sha256', $this->canonicalBytes())); }
    public static function fromCanonicalBytes(string $bytes): self { try { $value=json_decode($bytes, true, 512, JSON_THROW_ON_ERROR); if (!is_array($value) || CanonicalJson::encode($value)!==$bytes) throw new InvalidArgumentException(); return new self($value['report_code'], new ReportFilterSet($value['filters']), $value['comparison'], $value['locale'], $value['saved_view_id'], $value['format'], $value['columns'], new ReportWindowSort($value['sort']['field'], \App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection::from($value['sort']['direction'])), new DateTimeZone($value['timezone']), $value['period_policy'], $value['contract_version'], new Sha256Hash($value['definition_sha256'])); } catch (\Throwable $e) { throw new InvalidArgumentException('report_subscription_execution_input_invalid', 0, $e); } }
}
