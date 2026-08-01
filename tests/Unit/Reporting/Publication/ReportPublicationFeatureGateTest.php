<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationFeatureGate;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationFeatureConfiguration;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationIdentity;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationFeatureMode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportPublicationFeatureGateTest extends TestCase
{
    #[DataProvider('accessProvider')]
    public function test_mode_is_bound_to_exact_publication_and_action(
        ReportPublicationFeatureMode $mode,
        array $organizations,
        array $users,
        int $organizationId,
        int $userId,
        string $action,
        bool $expected,
    ): void {
        $identity = $this->identity();
        $configuration = new ReportPublicationFeatureConfiguration(
            'project_portfolio_health',
            $identity->publicationId,
            $identity->proofHash,
            $mode,
            $organizations,
            $users,
        );

        self::assertSame(
            $expected,
            (new ReportPublicationFeatureGate)->allows(
                $configuration,
                $identity,
                $organizationId,
                $userId,
                $action,
            ),
        );
    }

    public static function accessProvider(): iterable
    {
        yield 'off denies' => [ReportPublicationFeatureMode::OFF, [], [], 10, 20, 'run', false];
        yield 'canary organization runs' => [ReportPublicationFeatureMode::CANARY, [10], [], 10, 20, 'run', true];
        yield 'canary user exports' => [ReportPublicationFeatureMode::CANARY, [], [20], 10, 20, 'export', true];
        yield 'canary denies outsider' => [ReportPublicationFeatureMode::CANARY, [11], [21], 10, 20, 'download', false];
        yield 'canary never subscribes' => [ReportPublicationFeatureMode::CANARY, [10], [20], 10, 20, 'subscription', false];
        yield 'on allows' => [ReportPublicationFeatureMode::ON, [], [], 10, 20, 'download', true];
        yield 'disabled denies' => [ReportPublicationFeatureMode::DISABLED, [], [], 10, 20, 'run', false];
    }

    public function test_stale_publication_or_proof_is_denied_before_action(): void
    {
        $identity = $this->identity();
        $configuration = new ReportPublicationFeatureConfiguration(
            $identity->code,
            '01J00000000000000000000001',
            $identity->proofHash,
            ReportPublicationFeatureMode::ON,
            [],
            [],
        );

        self::assertFalse((new ReportPublicationFeatureGate)->allows(
            $configuration,
            $identity,
            10,
            20,
            'run',
        ));
    }

    public function test_disabled_publication_keeps_only_audit_history_visible(): void
    {
        $identity = $this->identity();
        $configuration = new ReportPublicationFeatureConfiguration(
            $identity->code,
            $identity->publicationId,
            $identity->proofHash,
            ReportPublicationFeatureMode::DISABLED,
            [],
            [],
        );

        $gate = new ReportPublicationFeatureGate;

        self::assertFalse($gate->allows($configuration, $identity, 10, 20, 'download'));
        self::assertTrue($gate->allowsAudit($configuration, $identity));
    }

    public function test_historical_publication_audit_survives_feature_rebinding_for_same_code(): void
    {
        $historical = $this->identity();
        $current = new ReportPublicationFeatureConfiguration(
            $historical->code,
            '01J00000000000000000000001',
            new Sha256Hash(str_repeat('f', 64)),
            ReportPublicationFeatureMode::ON,
            [],
            [],
        );

        self::assertTrue((new ReportPublicationFeatureGate)->allowsAudit($current, $historical));
    }

    public function test_canary_requires_an_explicit_allowlist(): void
    {
        $identity = $this->identity();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_feature_configuration_invalid');

        new ReportPublicationFeatureConfiguration(
            $identity->code,
            $identity->publicationId,
            $identity->proofHash,
            ReportPublicationFeatureMode::CANARY,
            [],
            [],
        );
    }

    private function identity(): ReportPublicationIdentity
    {
        return new ReportPublicationIdentity(
            '01J00000000000000000000000',
            'project_portfolio_health',
            new Sha256Hash(str_repeat('a', 64)),
            str_repeat('b', 40),
        );
    }
}
