<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ReportSavedViewRef
{
    public function __construct(
        public string $id,
        public int $revision,
        public Sha256Hash $hash,
    ) {
        if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $id) !== 1 || $revision < 1) {
            throw new InvalidArgumentException('report_saved_view_reference_invalid');
        }
    }

    public static function fromView(ReportSavedView $view): self
    {
        return new self(
            $view->id,
            max(1, (int) $view->updatedAt->format('Uu')),
            new Sha256Hash(hash('sha256', CanonicalJson::encode([
                'id' => $view->id,
                'report_code' => $view->reportCode,
                'contract_version' => $view->contractVersion,
                'name' => $view->name,
                'visibility' => $view->visibility,
                'filters' => $view->filters->values,
                'comparison' => $view->comparison,
                'sort' => ['field' => $view->sort->field, 'direction' => $view->sort->direction->value],
                'columns' => $view->columns,
                'status' => $view->status,
                'is_default' => $view->isDefault,
                'updated_at' => $view->updatedAt->format('Y-m-d\TH:i:s.u\Z'),
            ]))),
        );
    }
}
