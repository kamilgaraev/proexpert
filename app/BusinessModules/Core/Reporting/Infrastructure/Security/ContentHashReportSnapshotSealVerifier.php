<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Security;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportSnapshotSealVerifier;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportSnapshotSealVerificationInput;

final readonly class ContentHashReportSnapshotSealVerifier implements ReportSnapshotSealVerifier
{
    public function assertTrusted(ReportSnapshotSealVerificationInput $input): void
    {
        if (!hash_equals($input->seal->sealedPayloadHash->value, $input->calculatedSourceHash->value)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_OFFICIAL_SNAPSHOT_UNSEALED);
        }

        if ($input->seal->algorithm === 'sha256') {
            $rawHash = hex2bin($input->calculatedSourceHash->value);
            $expected = is_string($rawHash)
                ? rtrim(strtr(base64_encode($rawHash), '+/', '-_'), '=')
                : '';
            if ($input->seal->keyId !== 'content_hash_v1'
                || !hash_equals($expected, $input->seal->signature)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_OFFICIAL_SNAPSHOT_UNSEALED);
            }
        }
    }
}
