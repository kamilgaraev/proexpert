<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportAuthorizationFacts;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\ProductionReportScopedResourceAuthorizers;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\QueryReportScopedResourceAuthorizer;
use App\Models\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryReportScopedResourceAuthorizerTest extends TestCase
{
    public function test_current_exact_resource_is_allowed(): void
    {
        $exists = true;
        $authorizer = new QueryReportScopedResourceAuthorizer(
            'quality_defect_photo',
            static function () use (&$exists): bool {
                return $exists;
            },
        );

        self::assertTrue($authorizer->authorize(
            $this->actor(),
            2,
            $this->resource(),
            $this->facts(),
        )->granted);
    }

    public function test_mismatched_authorization_facts_are_denied_without_querying_resource(): void
    {
        $queried = false;
        $authorizer = new QueryReportScopedResourceAuthorizer(
            'quality_defect_photo',
            static function () use (&$queried): bool {
                $queried = true;

                return true;
            },
        );
        $other = new ReportScopedResource('quality_defect_photo', 15, 7);

        self::assertFalse($authorizer->authorize(
            $this->actor(),
            2,
            $this->resource(),
            new CurrentReportAuthorizationFacts('queue', 5, 2, 7, $other, new DateTimeImmutable),
        )->granted);
        self::assertFalse($queried);
    }

    public function test_resource_revoked_between_checks_is_denied_on_next_decision(): void
    {
        $exists = true;
        $authorizer = new QueryReportScopedResourceAuthorizer(
            'quality_defect_photo',
            static function () use (&$exists): bool {
                return $exists;
            },
        );
        self::assertTrue($authorizer->authorize($this->actor(), 2, $this->resource(), $this->facts())->granted);

        $exists = false;

        self::assertFalse($authorizer->authorize($this->actor(), 2, $this->resource(), $this->facts())->granted);
    }

    public function test_production_registry_has_exact_handlers_for_every_emitted_kind(): void
    {
        $kinds = array_map(
            static fn ($handler): string => $handler->kind(),
            (new ProductionReportScopedResourceAuthorizers)->handlers(),
        );
        sort($kinds);
        $expected = [
            'briefing',
            'contractor',
            'corrective_action_verification',
            'employee_requirement',
            'incident_cancellation',
            'incident_closure',
            'medical_exam',
            'ppe',
            'quality_defect',
            'quality_defect_photo',
            'safety_corrective_action',
            'safety_incident',
            'safety_site',
            'safety_violation',
            'schedule_task',
            'status_comment',
            'task',
            'training',
            'violation_resolution',
            'workforce_assignment',
            'workforce_employee',
        ];

        self::assertSame($expected, $kinds);
    }

    #[DataProvider('emittedKinds')]
    public function test_every_emitted_kind_is_allowed_denied_and_revoked_by_exact_current_facts(string $kind): void
    {
        $exists = true;
        $resource = new ReportScopedResource($kind, 14, 7);
        $facts = new CurrentReportAuthorizationFacts(
            'queue',
            5,
            2,
            7,
            $resource,
            new DateTimeImmutable('2026-07-30T10:00:00+00:00'),
        );
        $authorizer = new QueryReportScopedResourceAuthorizer(
            $kind,
            static function () use (&$exists): bool {
                return $exists;
            },
        );

        self::assertTrue($authorizer->authorize($this->actor(), 2, $resource, $facts)->granted);
        self::assertFalse($authorizer->authorize(
            $this->actor(),
            2,
            $resource,
            new CurrentReportAuthorizationFacts(
                'queue',
                5,
                3,
                7,
                $resource,
                new DateTimeImmutable('2026-07-30T10:00:00+00:00'),
            ),
        )->granted);

        $exists = false;

        self::assertFalse($authorizer->authorize($this->actor(), 2, $resource, $facts)->granted);
    }

    public static function emittedKinds(): array
    {
        return array_combine(
            self::emittedKindValues(),
            array_map(static fn (string $kind): array => [$kind], self::emittedKindValues()),
        );
    }

    private static function emittedKindValues(): array
    {
        return [
            'briefing',
            'contractor',
            'corrective_action_verification',
            'employee_requirement',
            'incident_cancellation',
            'incident_closure',
            'medical_exam',
            'ppe',
            'quality_defect',
            'quality_defect_photo',
            'safety_corrective_action',
            'safety_incident',
            'safety_site',
            'safety_violation',
            'schedule_task',
            'status_comment',
            'task',
            'training',
            'violation_resolution',
            'workforce_assignment',
            'workforce_employee',
        ];
    }

    private function actor(): User
    {
        $actor = new User;
        $actor->id = 5;

        return $actor;
    }

    private function resource(): ReportScopedResource
    {
        return new ReportScopedResource('quality_defect_photo', 14, 7);
    }

    private function facts(): CurrentReportAuthorizationFacts
    {
        return new CurrentReportAuthorizationFacts(
            'queue',
            5,
            2,
            7,
            $this->resource(),
            new DateTimeImmutable('2026-07-30T10:00:00+00:00'),
        );
    }
}
