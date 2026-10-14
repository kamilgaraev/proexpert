<?php

declare(strict_types=1);

namespace App\Services\Export;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

final class ExportValueBinder extends DefaultValueBinder
{
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $numeric = preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/D', $value) === 1
                && is_finite((float) $value)
                && parent::dataTypeForValue($value) === DataType::TYPE_NUMERIC;
            $cell->setValueExplicit($value, $numeric ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
