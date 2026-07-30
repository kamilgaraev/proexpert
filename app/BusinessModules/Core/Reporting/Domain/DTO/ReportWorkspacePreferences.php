<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportWorkspacePreferences
{
    public function __construct(
        public array $recentReportCodes,
        public array $favouriteReportCodes,
        public ReportWorkspaceDisplayPreferences $display,
        public DateTimeImmutable $updatedAt,
    ) {
        if (
            ! array_is_list($recentReportCodes)
            || ! array_is_list($favouriteReportCodes)
            || count($recentReportCodes) > 10
            || count(array_unique($recentReportCodes, SORT_STRING)) !== count($recentReportCodes)
            || count(array_unique($favouriteReportCodes, SORT_STRING)) !== count($favouriteReportCodes)
            || ! self::codesAreSafe($recentReportCodes)
            || ! self::codesAreSafe($favouriteReportCodes)
        ) {
            throw new InvalidArgumentException('report_workspace_preferences_invalid');
        }
    }

    public static function defaults(): self
    {
        return new self([], [], ReportWorkspaceDisplayPreferences::defaults(), new DateTimeImmutable('now'));
    }

    private static function codesAreSafe(array $codes): bool
    {
        foreach ($codes as $code) {
            if (! is_string($code) || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $code) !== 1) {
                return false;
            }
        }

        return true;
    }
}
