<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;

final class ReportingPermissionMatrix
{
    public const VIEW = 'reports.view';
    public const RUN = 'reports.run';
    public const EXPORT = 'reports.export';
    public const DOWNLOAD = 'reports.download';
    public const MANAGE = 'reports.manage';
    public const SENSITIVE = 'reports.sensitive';
    public const AUDIT = 'reports.audit';

    public static function corePermissions(): array
    {
        return [
            self::VIEW,
            self::RUN,
            self::EXPORT,
            self::DOWNLOAD,
            self::MANAGE,
            self::SENSITIVE,
            self::AUDIT,
        ];
    }

    public static function operationRequirements(): array
    {
        return [
            ReportOperation::VIEW->value => [self::VIEW],
            ReportOperation::RUN->value => [self::VIEW, self::RUN],
            ReportOperation::EXPORT->value => [self::VIEW, self::EXPORT],
            ReportOperation::DOWNLOAD->value => [self::VIEW, self::EXPORT, self::DOWNLOAD],
            ReportOperation::MANAGE->value => [self::VIEW, self::MANAGE],
            ReportOperation::VIEW_SENSITIVE->value => [self::VIEW, self::SENSITIVE],
            ReportOperation::VIEW_AUDIT->value => [self::VIEW, self::AUDIT],
            ReportOperation::DRILL_DOWN->value => [self::VIEW],
        ];
    }

    public static function requiredFor(ReportOperation $operation): array
    {
        return self::operationRequirements()[$operation->value];
    }

    public static function permissionChecks(): array
    {
        return [
            'base_view' => self::requiredFor(ReportOperation::VIEW),
            'run' => [self::RUN],
            'export' => [self::EXPORT],
            'download' => [self::DOWNLOAD],
            'manage' => [self::MANAGE],
            'sensitive' => [self::SENSITIVE],
            'audit' => [self::AUDIT],
        ];
    }

    public static function middleware(ReportOperation $operation): array
    {
        return array_map(
            static fn (string $permission): string => 'authorize:'.$permission,
            self::requiredFor($operation),
        );
    }
}
