<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Input;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;

final class ReportDrillDownNormalizer
{
    public function normalize(array $input): ReportDrillDownRequest
    {
        $keys = array_keys($input);
        sort($keys);

        if ($keys !== ['cursor', 'limit', 'token']
            || !is_string($input['token'])
            || trim($input['token']) === ''
            || ($input['cursor'] !== null && !is_string($input['cursor']))
            || !is_int($input['limit'])
            || $input['limit'] < 1
            || $input['limit'] > 100) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        return new ReportDrillDownRequest(trim($input['token']), $input['cursor'], $input['limit']);
    }
}
