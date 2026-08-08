<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportSnapshotSealVerificationInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotSeal;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Security\ContentHashReportSnapshotSealVerifier;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/Domain/DTO/ReportSnapshotSeal.php';
require_once dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/Application/Execution/ReportSnapshotSealVerificationInput.php';
require_once dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/Infrastructure/Security/ContentHashReportSnapshotSealVerifier.php';

final class ContentHashReportSnapshotSealVerifierTest extends TestCase
{
    public function test_accepts_digest_derived_from_the_calculated_source_hash(): void
    {
        $hash = new Sha256Hash(str_repeat('a', 64));

        (new ContentHashReportSnapshotSealVerifier())->assertTrusted($this->input($hash, $hash));

        self::addToAssertionCount(1);
    }

    public function test_rejects_digest_not_derived_from_the_calculated_source_hash(): void
    {
        $this->expectException(ReportContractException::class);

        $hash = new Sha256Hash(str_repeat('a', 64));
        $otherHash = new Sha256Hash(str_repeat('b', 64));
        (new ContentHashReportSnapshotSealVerifier())->assertTrusted($this->input($hash, $otherHash));
    }

    private function input(Sha256Hash $sealedHash, Sha256Hash $digestHash): ReportSnapshotSealVerificationInput
    {
        $generatedAt = new DateTimeImmutable('2026-08-08T00:00:00Z');
        $rawHash = hex2bin($digestHash->value);
        self::assertIsString($rawHash);
        $seal = new ReportSnapshotSeal(
            'content_hash_v1',
            'sha256',
            $sealedHash,
            rtrim(strtr(base64_encode($rawHash), '+/', '-_'), '='),
            $generatedAt,
        );

        return new ReportSnapshotSealVerificationInput(
            $seal,
            'snapshot-1',
            'attendance_execution',
            ReportSnapshotClassification::OFFICIAL,
            $generatedAt,
            $sealedHash,
        );
    }
}
