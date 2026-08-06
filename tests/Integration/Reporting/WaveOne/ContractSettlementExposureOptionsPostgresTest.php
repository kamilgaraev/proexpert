<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\WaveOne;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureOptionsService;
use App\Models\Organization;
use App\Models\Project;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

#[Group('postgres')]
final class ContractSettlementExposureOptionsPostgresTest extends TestCase
{
    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function outermost_options_read_uses_a_valid_read_only_repeatable_read_transaction(): void
    {
        $this->requirePostgres();

        self::assertSame(0, DB::transactionLevel());
        $scope = new ReportScope(
            799999981,
            [799999981],
            [799999982],
            [],
            new DateTimeZone('Europe/Moscow'),
        );

        $options = $this->app->make(ContractSettlementExposureOptionsService::class)->options(
            $scope,
            new DateTimeImmutable('2026-08-06T14:15:16.123456+03:00'),
        );

        self::assertFalse($options['available']);
        self::assertSame(0, DB::transactionLevel());
    }

    #[Test]
    public function latest_owner_payload_excludes_future_delete_and_other_tenant_versions(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();

        try {
            $organizationId = random_int(700000000, 709999999);
            $otherOrganizationId = $organizationId + 1;
            $activeOwnerId = random_int(710000000, 719999999);
            $deletedOwnerId = $activeOwnerId + 1;
            $firstAt = new DateTimeImmutable('2026-08-06T10:00:00.100000+00:00');
            $asOf = new DateTimeImmutable('2026-08-06T11:00:00.200000+00:00');
            $futureAt = new DateTimeImmutable('2026-08-06T12:00:00.300000+00:00');

            $this->insertOwnerVersion(
                $organizationId,
                $activeOwnerId,
                1,
                'upsert',
                $firstAt,
                ['marker' => 'target-v1'],
            );
            $this->insertOwnerVersion(
                $organizationId,
                $activeOwnerId,
                2,
                'upsert',
                $asOf,
                ['marker' => 'target-v2'],
            );
            $this->insertOwnerVersion(
                $organizationId,
                $activeOwnerId,
                3,
                'upsert',
                $futureAt,
                ['marker' => 'target-future'],
            );
            $this->insertOwnerVersion(
                $otherOrganizationId,
                $activeOwnerId,
                99,
                'upsert',
                $asOf,
                ['marker' => 'other-tenant'],
            );
            $this->insertOwnerVersion(
                $organizationId,
                $deletedOwnerId,
                1,
                'upsert',
                $firstAt,
                ['marker' => 'before-delete'],
            );
            $this->insertOwnerVersion(
                $organizationId,
                $deletedOwnerId,
                2,
                'delete',
                $asOf,
                [],
            );
            $this->insertOwnerVersion(
                $organizationId,
                $deletedOwnerId,
                3,
                'upsert',
                $futureAt,
                ['marker' => 'future-restore'],
            );

            $payloads = $this->ownerPayloads(
                new ReportScope(
                    $organizationId,
                    [$organizationId],
                    [],
                    [],
                    new DateTimeZone('UTC'),
                ),
                $asOf,
                [$activeOwnerId, $deletedOwnerId],
            );

            self::assertSame([$activeOwnerId], array_keys($payloads));
            self::assertSame('target-v2', $payloads[$activeOwnerId]['marker']);
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function project_options_include_authorized_shared_project_and_reject_ids_outside_scope(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();

        try {
            $scopeOrganization = Organization::withoutEvents(
                static fn (): Organization => Organization::factory()->create(),
            );
            $projectOwner = Organization::withoutEvents(
                static fn (): Organization => Organization::factory()->create(),
            );
            $authorized = Project::withoutEvents(
                static fn (): Project => Project::factory()
                    ->for($projectOwner)
                    ->create(['name' => 'Общий проект']),
            );
            $outsideScope = Project::withoutEvents(
                static fn (): Project => Project::factory()
                    ->for($projectOwner)
                    ->create(['name' => 'Чужой проект']),
            );
            $scope = new ReportScope(
                (int) $scopeOrganization->id,
                [(int) $scopeOrganization->id],
                [(int) $authorized->id],
                [],
                new DateTimeZone('UTC'),
            );

            $method = new ReflectionMethod(ContractSettlementExposureOptionsService::class, 'projectOptions');
            $options = $method->invoke(
                $this->app->make(ContractSettlementExposureOptionsService::class),
                $scope,
                [(int) $authorized->id, (int) $outsideScope->id],
            );

            self::assertSame([
                ['id' => (int) $authorized->id, 'name' => 'Общий проект'],
            ], $options);
        } finally {
            DB::rollBack();
        }
    }

    /** @param array<string, mixed> $payload */
    private function insertOwnerVersion(
        int $organizationId,
        int $ownerId,
        int $version,
        string $operation,
        DateTimeImmutable $occurredAt,
        array $payload,
    ): void {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $now = new DateTimeImmutable('2026-08-06T13:00:00+00:00');

        DB::table('contract_settlement_owner_versions')->insert([
            'organization_id' => $organizationId,
            'owner_type' => 'contract',
            'owner_id' => (string) $ownerId,
            'version' => $version,
            'operation' => $operation,
            'occurred_at' => $occurredAt,
            'payload' => $encoded,
            'owner_hash' => hash('sha256', implode('|', [
                $organizationId,
                $ownerId,
                $version,
                $operation,
                $encoded,
            ])),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param list<int> $ownerIds
     * @return array<int, array<string, mixed>>
     */
    private function ownerPayloads(ReportScope $scope, DateTimeImmutable $asOf, array $ownerIds): array
    {
        $method = new ReflectionMethod(ContractSettlementExposureOptionsService::class, 'ownerPayloads');

        return $method->invoke(
            $this->app->make(ContractSettlementExposureOptionsService::class),
            $scope,
            $asOf,
            'contract',
            $ownerIds,
        );
    }

    private function requirePostgres(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }
    }

}
