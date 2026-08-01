<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Rows;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ReportCursorRow
{
    public function __construct(
        public string $rowKey,
        public array $values,
        public string $snapshotId,
        public Sha256Hash $queryHash,
        public Sha256Hash $sourceHash,
    ) {
        if (trim($rowKey) === ''
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $snapshotId) !== 1
            || array_is_list($values)) {
            throw new InvalidArgumentException('report_cursor_row_invalid');
        }

        try {
            CanonicalJson::encode($values);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('report_cursor_row_invalid', 0, $exception);
        }
    }
}
