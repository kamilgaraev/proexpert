<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Contracts;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewReferenceResolver;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotSeal;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

final class ReportProviderPortContractTest extends TestCase
{
    public function test_provider_scope_exposes_only_typed_resources_collection(): void
    {
        self::assertSame('array', (string) (new ReflectionProperty(ReportScope::class, 'resources'))->getType());
        self::assertFalse(property_exists(ReportScope::class, 'resourceIds'));
    }

    public function test_saved_view_resolver_has_exact_typed_surface(): void
    {
        $resolver = new ReflectionClass(ReportSavedViewReferenceResolver::class);

        self::assertTrue($resolver->isInterface());
        self::assertSame([
            'resolve' => [
                'parameters' => [
                    ['context', \App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext::class, false, null, false, false],
                    ['savedViewId', 'string', false, null, false, false],
                ],
                'static' => false,
                'return' => ReportSavedViewRef::class,
            ],
            'assertCurrent' => [
                'parameters' => [
                    ['context', \App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext::class, false, null, false, false],
                    ['reference', ReportSavedViewRef::class, false, null, false, false],
                ],
                'static' => false,
                'return' => 'void',
            ],
        ], array_combine(
            array_map(static fn (ReflectionMethod $method): string => $method->getName(), $resolver->getMethods()),
            array_map(self::methodSignature(...), $resolver->getMethods()),
        ));
    }

    public function test_provider_ports_keep_the_exact_snapshot_boundary(): void
    {
        self::assertSame(['materialize', 'result'], array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(ReportDataProvider::class))->getMethods(),
        ));
        self::assertSame(['page', 'cursor'], array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(ReportRowQuery::class))->getMethods(),
        ));
        self::assertSame(['drillDown'], array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(ReportDrillDownProvider::class))->getMethods(),
        ));
    }

    public function test_official_snapshot_requires_a_valid_seal_not_older_than_generation(): void
    {
        $generatedAt = new DateTimeImmutable('2026-07-28T10:00:00.000000Z');
        $seal = $this->seal(new DateTimeImmutable('2026-07-28T10:00:01.000000Z'));
        $snapshot = $this->snapshot(ReportSnapshotClassification::OFFICIAL, $seal, $generatedAt);

        self::assertSame($seal, $snapshot->seal);
        self::assertSame(ReportSnapshotClassification::OFFICIAL, $snapshot->classification);
    }

    public function test_operational_snapshot_forbids_a_seal(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->snapshot(
            ReportSnapshotClassification::OPERATIONAL,
            $this->seal(new DateTimeImmutable('2026-07-28T10:00:01Z')),
            new DateTimeImmutable('2026-07-28T10:00:00Z'),
        );
    }

    public function test_official_snapshot_rejects_missing_or_early_seal(): void
    {
        $generatedAt = new DateTimeImmutable('2026-07-28T10:00:00Z');
        $rejections = 0;

        foreach ([null, $this->seal(new DateTimeImmutable('2026-07-28T09:59:59Z'))] as $seal) {
            try {
                $this->snapshot(ReportSnapshotClassification::OFFICIAL, $seal, $generatedAt);
                self::fail('Expected official snapshot seal rejection.');
            } catch (InvalidArgumentException) {
                $rejections++;
            }
        }

        self::assertSame(2, $rejections);
    }

    #[DataProvider('invalidSnapshotIdentities')]
    public function test_snapshot_rejects_noncanonical_kind_or_id(string $kind, string $id): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->snapshot(ReportSnapshotClassification::OPERATIONAL, null, id: $id, kind: $kind);
    }

    public static function invalidSnapshotIdentities(): array
    {
        return [
            'empty kind' => ['', 'snapshot'],
            'uppercase kind' => ['Report', 'snapshot'],
            'kind whitespace' => [' report', 'snapshot'],
            'empty id' => ['report', ''],
            'id whitespace' => ['report', ' snapshot'],
            'invalid id character' => ['report', 'snap/shot'],
        ];
    }

    #[DataProvider('invalidSeals')]
    public function test_seal_rejects_invalid_key_algorithm_or_signature(
        string $keyId,
        string $algorithm,
        string $signature,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new ReportSnapshotSeal(
            $keyId,
            $algorithm,
            new Sha256Hash(str_repeat('a', 64)),
            $signature,
            new DateTimeImmutable('2026-07-28T10:00:00Z'),
        );
    }

    public static function invalidSeals(): array
    {
        return [
            'short key' => ['ab', 'ed25519-sha256', str_repeat('A', 86)],
            'uppercase key' => ['Key', 'ed25519-sha256', str_repeat('A', 86)],
            'algorithm' => ['key_1', 'rsa-sha256', str_repeat('A', 86)],
            'padded signature' => ['key_1', 'ed25519-sha256', str_repeat('A', 86).'=='],
            'short signature' => ['key_1', 'ed25519-sha256', str_repeat('A', 85)],
            'noncanonical signature' => ['key_1', 'ed25519-sha256', str_repeat('_', 86)],
        ];
    }

    private function snapshot(
        ReportSnapshotClassification $classification,
        ?ReportSnapshotSeal $seal,
        ?DateTimeImmutable $generatedAt = null,
        string $id = 'snapshot',
        string $kind = 'report',
    ): ReportSnapshotRef {
        return new ReportSnapshotRef(
            $kind,
            $id,
            new ReportScope(1, [1], [], [], new DateTimeZone('UTC')),
            new Sha256Hash(str_repeat('a', 64)),
            '1',
            new Sha256Hash(str_repeat('b', 64)),
            $generatedAt ?? new DateTimeImmutable('2026-07-28T10:00:00Z'),
            null,
            [],
            $classification,
            $seal,
        );
    }

    private function seal(DateTimeImmutable $sealedAt): ReportSnapshotSeal
    {
        return new ReportSnapshotSeal(
            'key_1',
            'ed25519-sha256',
            new Sha256Hash(str_repeat('c', 64)),
            str_repeat('A', 86),
            $sealedAt,
        );
    }

    private static function methodSignature(ReflectionMethod $method): array
    {
        return [
            'parameters' => array_map(
                static fn (ReflectionParameter $parameter): array => [
                    $parameter->getName(),
                    (string) $parameter->getType(),
                    $parameter->isDefaultValueAvailable(),
                    $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                    $parameter->isPassedByReference(),
                    $parameter->isVariadic(),
                ],
                $method->getParameters(),
            ),
            'static' => $method->isStatic(),
            'return' => (string) $method->getReturnType(),
        ];
    }
}
