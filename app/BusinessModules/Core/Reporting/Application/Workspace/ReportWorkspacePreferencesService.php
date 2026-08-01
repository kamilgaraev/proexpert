<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Workspace;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAccessService;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportWorkspacePreferencesStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWorkspaceDisplayPreferences;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWorkspacePreferences;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;

final readonly class ReportWorkspacePreferencesService
{
    public function __construct(
        private ReportWorkspacePreferencesStore $store,
        private ReportDefinitionRegistry $definitions,
        private ReportAccessService $access,
    ) {}

    public function get(ReportExecutionContext $context): ReportWorkspacePreferences
    {
        $preferences = $this->store->get($context->scope->organizationId, $context->actor->id)
            ?? ReportWorkspacePreferences::defaults();
        $this->assertViewable($context, [
            ...$preferences->recentReportCodes,
            ...$preferences->favouriteReportCodes,
        ]);

        return $preferences;
    }

    public function recordRecent(ReportExecutionContext $context, string $reportCode): ReportWorkspacePreferences
    {
        $this->assertMutable($context, [$reportCode]);

        return $this->store->updateLocked(
            $context->scope->organizationId,
            $context->actor->id,
            static function (ReportWorkspacePreferences $current) use ($reportCode): ReportWorkspacePreferences {
                $recent = array_values(array_filter(
                    $current->recentReportCodes,
                    static fn (string $code): bool => $code !== $reportCode,
                ));
                array_unshift($recent, $reportCode);

                return new ReportWorkspacePreferences(
                    array_slice($recent, 0, 10),
                    $current->favouriteReportCodes,
                    $current->display,
                    $current->updatedAt,
                );
            },
        );
    }

    public function setFavourites(ReportExecutionContext $context, array $codes): ReportWorkspacePreferences
    {
        $codes = $this->uniqueCodes($codes);
        $this->assertMutable($context, $codes);

        return $this->store->updateLocked(
            $context->scope->organizationId,
            $context->actor->id,
            static fn (ReportWorkspacePreferences $current) => new ReportWorkspacePreferences(
                $current->recentReportCodes,
                $codes,
                $current->display,
                $current->updatedAt,
            ),
        );
    }

    public function updateDisplay(
        ReportExecutionContext $context,
        ReportWorkspaceDisplayPreferences $display,
    ): ReportWorkspacePreferences {
        $current = $this->store->get($context->scope->organizationId, $context->actor->id)
            ?? ReportWorkspacePreferences::defaults();
        $this->assertViewable($context, [
            ...$current->recentReportCodes,
            ...$current->favouriteReportCodes,
        ]);

        return $this->store->updateLocked(
            $context->scope->organizationId,
            $context->actor->id,
            static fn (ReportWorkspacePreferences $current) => new ReportWorkspacePreferences(
                $current->recentReportCodes,
                $current->favouriteReportCodes,
                $display,
                $current->updatedAt,
            ),
        );
    }

    private function assertMutable(ReportExecutionContext $context, array $codes): void
    {
        $this->assertViewable($context, $codes);
        foreach ($codes as $code) {
            $this->access->assertOperation(
                $context,
                $this->definitions->published($code)->payload(),
                ReportOperation::MANAGE,
                null,
            );
        }
    }

    private function assertViewable(ReportExecutionContext $context, array $codes): void
    {
        foreach ($this->uniqueCodes($codes) as $code) {
            $this->access->assertOperation(
                $context,
                $this->definitions->published($code)->payload(),
                ReportOperation::VIEW,
                null,
            );
        }
    }

    private function uniqueCodes(array $codes): array
    {
        $unique = [];
        foreach ($codes as $code) {
            if (! is_string($code) || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $code) !== 1) {
                throw new \InvalidArgumentException('report_workspace_code_invalid');
            }
            if (! in_array($code, $unique, true)) {
                $unique[] = $code;
            }
        }

        return $unique;
    }
}
