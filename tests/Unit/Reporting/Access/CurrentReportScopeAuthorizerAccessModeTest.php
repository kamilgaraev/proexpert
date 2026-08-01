<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportAuthorizationFacts;
use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportPermissionDecision;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionModuleAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionVisibilityResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\CurrentReportAbacEvaluator;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\DeterministicReportModuleEntitlement;
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

    public function test_source_module_revocation_zeroes_visibility_before_permissions_are_evaluated(): void
    {
        $evaluator = new RecordingAccessModeAbacEvaluator([
            'act_reports.view',
            'act_reports.export.excel',
        ]);

        $vector = $this->permissionVector($evaluator, moduleAllowed: false);

        self::assertSame([
            'view' => false,
            'run' => false,
            'export' => false,
            'download' => false,
            'manage' => false,
            'sensitive' => false,
            'audit' => false,
        ], $vector);
        self::assertSame([], $evaluator->permissions);
    }

    private function permissionVector(
        RecordingAccessModeAbacEvaluator $evaluator,
        ?ReportScope $scope = null,
        array $formats = ['xlsx'],
        ?string $exportFormat = null,
        bool $moduleAllowed = true,
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
        $scope ??= new ReportScope(7, [7], [], [], new DateTimeZone('UTC'));
        $visibility = (new ReportDefinitionVisibilityResolver(
            new ReportDefinitionModuleAuthorizer(new DeterministicReportModuleEntitlement(
                $moduleAllowed ? ['act-reporting'] : [],
                [7],
            )),
        ))->resolve(
            $scope->organizationId,
            $definition,
            $exportFormat === null ? ReportOperation::VIEW : ReportOperation::EXPORT,
            $exportFormat,
            function (string $permission) use ($evaluator, $scope): bool {
                $facts = [new CurrentReportAuthorizationFacts(
                    'queue',
                    41,
                    $scope->organizationId,
                    null,
                    null,
                    new DateTimeImmutable('2026-08-01T00:00:00Z'),
                )];
                foreach ($scope->projectIds as $projectId) {
                    $facts[] = new CurrentReportAuthorizationFacts(
                        'queue',
                        41,
                        $scope->organizationId,
                        $projectId,
                        null,
                        new DateTimeImmutable('2026-08-01T00:00:00Z'),
                    );
                }
                foreach ($facts as $fact) {
                    if (! $evaluator->evaluate(41, $permission, $fact)->granted) {
                        return false;
                    }
                }

                return true;
            },
        );

        return [
            'view' => $visibility->canView,
            'run' => $visibility->canRun,
            'export' => $visibility->canExport,
            'download' => $visibility->canDownload,
            'manage' => $visibility->canManage,
            'sensitive' => $visibility->canViewSensitive,
            'audit' => $visibility->canViewAudit,
        ];
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
