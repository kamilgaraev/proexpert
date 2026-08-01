<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportAuthorizationFacts;
use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportPermissionDecision;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\CurrentReportAbacEvaluator;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelReportScopedResourceAuthorizerRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Execution\LaravelCurrentReportScopeAuthorizer;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class CurrentReportScopeAuthorizerAccessModeTest extends TestCase
{
    public function test_source_mode_current_authorization_uses_only_definition_permissions(): void
    {
        $evaluator = new RecordingAccessModeAbacEvaluator(['act_reports.view']);
        $vector = $this->permissionVector($evaluator);

        self::assertSame([
            'view' => true,
            'run' => true,
            'export' => false,
            'download' => false,
            'manage' => false,
            'sensitive' => false,
            'audit' => false,
        ], $vector);
        self::assertSame(['act_reports.view', 'act_reports.export.excel'], $evaluator->permissions);
        self::assertNotContains('reports.view', $evaluator->permissions);
    }

    public function test_source_mode_exact_export_permission_enables_export_and_download_only(): void
    {
        $vector = $this->permissionVector(new RecordingAccessModeAbacEvaluator([
            'act_reports.view',
            'act_reports.export.excel',
        ]));

        self::assertTrue($vector['export']);
        self::assertTrue($vector['download']);
        self::assertFalse($vector['manage']);
        self::assertFalse($vector['sensitive']);
        self::assertFalse($vector['audit']);
    }

    public function test_source_mode_export_permission_is_selected_by_target_format(): void
    {
        $evaluator = new RecordingAccessModeAbacEvaluator([
            'act_reports.view',
            'act_reports.export.excel',
        ]);

        $excel = $this->permissionVector($evaluator, null, ['xlsx', 'pdf'], 'xlsx');
        $pdf = $this->permissionVector($evaluator, null, ['xlsx', 'pdf'], 'pdf');

        self::assertTrue($excel['export']);
        self::assertFalse($pdf['export']);
    }

    public function test_generic_permissions_cannot_replace_source_definition_permission(): void
    {
        $vector = $this->permissionVector(new RecordingAccessModeAbacEvaluator([
            'reports.view',
            'reports.run',
            'reports.export',
            'reports.download',
        ]));

        self::assertSame([
            'view' => false,
            'run' => false,
            'export' => false,
            'download' => false,
            'manage' => false,
            'sensitive' => false,
            'audit' => false,
        ], $vector);
    }

    public function test_source_permission_must_hold_for_every_project_fact(): void
    {
        $scope = new ReportScope(7, [7], [99], [], new DateTimeZone('UTC'));
        $vector = $this->permissionVector(
            new RecordingAccessModeAbacEvaluator(
                ['act_reports.view', 'act_reports.export.excel'],
                [99],
            ),
            $scope,
        );

        self::assertFalse($vector['view']);
        self::assertFalse($vector['run']);
        self::assertFalse($vector['export']);
        self::assertFalse($vector['download']);
    }

    private function permissionVector(
        RecordingAccessModeAbacEvaluator $evaluator,
        ?ReportScope $scope = null,
        array $formats = ['xlsx'],
        ?string $exportFormat = null,
    ): array {
        $exportPermissions = array_map(
            static fn (string $format): string => match ($format) {
                'xlsx' => 'act_reports.export.excel',
                'pdf' => 'act_reports.export.pdf',
            },
            $formats,
        );
        sort($exportPermissions, SORT_STRING);
        $definition = (new ReportDefinitionBuilder)
            ->sourceModule('act-reporting')
            ->coreAccessMode(ReportCoreAccessMode::SOURCE_MODULE_REPORT)
            ->formats($formats)
            ->permissionPolicy(new ReportPermissionPolicy(
                ['act_reports.view'],
                $exportPermissions,
                [],
                [],
            ))
            ->payload();
        $authorizer = new LaravelCurrentReportScopeAuthorizer(
            $evaluator,
            new LaravelReportScopedResourceAuthorizerRegistry([]),
        );
        $method = new \ReflectionMethod($authorizer, 'permissionVector');

        return $method->invoke(
            $authorizer,
            41,
            $scope ?? new ReportScope(7, [7], [], [], new DateTimeZone('UTC')),
            new CurrentReportAuthorizationTarget(
                $definition,
                $exportFormat === null ? ReportOperation::VIEW : ReportOperation::EXPORT,
                $exportFormat === null ? null : new ReportSnapshotRef(
                    'report',
                    'snapshot',
                    $scope ?? new ReportScope(7, [7], [], [], new DateTimeZone('UTC')),
                    $definition->definitionHash,
                    $definition->formulaVersion,
                    new Sha256Hash(str_repeat('f', 64)),
                    new DateTimeImmutable('2026-08-01T00:00:00Z'),
                    null,
                    [],
                    ReportSnapshotClassification::OPERATIONAL,
                    null,
                ),
                $exportFormat,
            ),
            new DateTimeImmutable('2026-08-01T00:00:00Z'),
        );
    }
}

final class RecordingAccessModeAbacEvaluator implements CurrentReportAbacEvaluator
{
    public array $permissions = [];

    public function __construct(
        private readonly array $granted,
        private readonly array $deniedProjectIds = [],
    ) {}

    public function evaluate(
        int $actorId,
        string $permission,
        CurrentReportAuthorizationFacts $facts,
    ): CurrentReportPermissionDecision {
        $this->permissions[] = $permission;

        return new CurrentReportPermissionDecision(
            $actorId,
            $permission,
            $facts->organizationId,
            $facts->projectId,
            $facts->resource,
            in_array($permission, $this->granted, true)
                && ! in_array($facts->projectId, $this->deniedProjectIds, true),
        );
    }
}
