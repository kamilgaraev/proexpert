<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;

final class ReportExportAuthorizationIdentity
{
    public static function fromRecord(ReportExportRecord $record): Sha256Hash
    {
        $attributes = $record->getRawOriginal();
        ksort($attributes, SORT_STRING);

        return new Sha256Hash(hash('sha256', CanonicalJson::encode($attributes)));
    }
}
