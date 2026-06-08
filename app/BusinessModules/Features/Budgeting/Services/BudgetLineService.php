<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetLine;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class BudgetLineService
{
    public function __construct(
        private readonly BudgetVersionService $versionService,
        private readonly BudgetPeriodClosureService $periodClosureService
    ) {
    }

    public function lines(User $user, string $versionUuid, array $filters): array
    {
        $version = $this->versionService->findVersion($user, $versionUuid);

        return BudgetLine::query()
            ->where('budget_version_id', $version->id)
            ->with(['article', 'responsibilityCenter', 'amounts'])
            ->when($filters['article_id'] ?? null, function (Builder $query, string $uuid): void {
                $query->whereHas('article', fn (Builder $article) => $article->where('uuid', $uuid));
            })
            ->when($filters['responsibility_center_id'] ?? null, function (Builder $query, string $uuid): void {
                $query->whereHas('responsibilityCenter', fn (Builder $center) => $center->where('uuid', $uuid));
            })
            ->when($filters['project_id'] ?? null, fn (Builder $query, int|string $projectId) => $query->where('project_id', (int) $projectId))
            ->orderBy('budget_article_id')
            ->orderBy('responsibility_center_id')
            ->get()
            ->map(fn (BudgetLine $line): array => $this->versionService->lineToArray($line))
            ->all();
    }

    public function replace(User $user, string $versionUuid, array $lines): array
    {
        $version = $this->versionService->findVersion($user, $versionUuid);
        $this->assertEditable($version);
        $normalized = [];
        $seen = [];

        foreach ($lines as $line) {
            $article = $this->articleByUuid($version, (string) $line['budget_article_id']);
            $center = $this->centerByUuid($version, (string) $line['responsibility_center_id']);
            $projectId = $this->nullableScopedProjectId($version, $line['project_id'] ?? null);
            $contractId = $this->nullableScopedContractId($version, $line['contract_id'] ?? null);
            $counterpartyId = $this->nullableScopedCounterpartyId($version, $line['counterparty_id'] ?? null);
            $currency = strtoupper((string) ($line['currency'] ?? 'RUB'));

            foreach ($line['amounts'] as $amount) {
                $month = $this->monthInsideVersion($version, (string) $amount['month']);
                $key = implode('|', [$article->id, $center->id, $month, $projectId, $contractId, $counterpartyId, $currency]);
                if (isset($seen[$key])) {
                    throw new \DomainException(trans_message('budgeting.lines.duplicate'));
                }
                $seen[$key] = true;

                $normalized[] = [
                    'budget_article_id' => $article->id,
                    'responsibility_center_id' => $center->id,
                    'project_id' => $projectId,
                    'contract_id' => $contractId,
                    'counterparty_id' => $counterpartyId,
                    'currency' => $currency,
                    'description' => $line['description'] ?? null,
                    'month' => $month,
                    'plan' => round((float) ($amount['plan'] ?? 0), 2),
                    'forecast' => round((float) ($amount['forecast'] ?? ($amount['plan'] ?? 0)), 2),
                ];
            }
        }

        $this->writeNormalizedRows($version, $normalized, 'replace_lines');

        return $this->lines($user, $versionUuid, []);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function writeNormalizedRows(BudgetVersion $version, array $rows, string $mode): void
    {
        $this->assertEditable($version);

        DB::transaction(function () use ($version, $rows, $mode): void {
            if ($mode === 'replace_lines') {
                BudgetLine::query()->where('budget_version_id', $version->id)->delete();
            }

            $grouped = [];
            foreach ($rows as $row) {
                $key = implode('|', [
                    $row['budget_article_id'],
                    $row['responsibility_center_id'],
                    $row['project_id'] ?? '',
                    $row['contract_id'] ?? '',
                    $row['counterparty_id'] ?? '',
                    $row['currency'] ?? 'RUB',
                    $row['description'] ?? '',
                ]);
                $grouped[$key]['line'] = $row;
                $grouped[$key]['amounts'][] = $row;
            }

            foreach ($grouped as $group) {
                $lineData = $group['line'];
                $line = BudgetLine::create([
                    'budget_version_id' => $version->id,
                    'budget_article_id' => $lineData['budget_article_id'],
                    'responsibility_center_id' => $lineData['responsibility_center_id'],
                    'project_id' => $lineData['project_id'] ?? null,
                    'contract_id' => $lineData['contract_id'] ?? null,
                    'counterparty_id' => $lineData['counterparty_id'] ?? null,
                    'currency' => $lineData['currency'] ?? 'RUB',
                    'description' => $lineData['description'] ?? null,
                ]);

                foreach ($group['amounts'] as $amount) {
                    BudgetAmount::create([
                        'budget_line_id' => $line->id,
                        'month' => $this->monthValue((string) $amount['month']),
                        'plan_amount' => $amount['plan'] ?? $amount['plan_amount'] ?? 0,
                        'forecast_amount' => $amount['forecast'] ?? $amount['forecast_amount'] ?? ($amount['plan'] ?? $amount['plan_amount'] ?? 0),
                        'currency' => $amount['currency'] ?? 'RUB',
                    ]);
                }
            }
        });
    }

    public function assertEditable(BudgetVersion $version): void
    {
        if ($version->status !== 'draft') {
            throw new \DomainException(trans_message('budgeting.versions.edit_forbidden'));
        }

        $this->periodClosureService->assertVersionPeriodMutable($version);
    }

    private function articleByUuid(BudgetVersion $version, string $uuid): BudgetArticle
    {
        $article = BudgetArticle::query()
            ->where('organization_id', $version->organization_id)
            ->where('uuid', $uuid)
            ->where('is_active', true)
            ->first();

        if (!$article instanceof BudgetArticle) {
            throw new \DomainException(trans_message('budgeting.articles.not_found'));
        }

        if (!$article->is_leaf) {
            throw new \DomainException(trans_message('budgeting.articles.not_leaf'));
        }

        if (!in_array($article->budget_kind, [$version->budget_kind, 'both'], true)) {
            throw new \DomainException(trans_message('budgeting.articles.kind_mismatch'));
        }

        return $article;
    }

    private function centerByUuid(BudgetVersion $version, string $uuid): ResponsibilityCenter
    {
        $center = ResponsibilityCenter::query()
            ->where('organization_id', $version->organization_id)
            ->where('uuid', $uuid)
            ->where('is_active', true)
            ->first();

        if (!$center instanceof ResponsibilityCenter) {
            throw new \DomainException(trans_message('budgeting.cfo.not_found'));
        }

        return $center;
    }

    private function nullableScopedProjectId(BudgetVersion $version, mixed $projectId): ?int
    {
        if ($projectId === null || $projectId === '') {
            return null;
        }

        $exists = Project::query()->where('id', (int) $projectId)->accessibleByOrganization((int) $version->organization_id)->exists();
        if (!$exists) {
            throw new \DomainException(trans_message('budgeting.lines.project_not_found'));
        }

        return (int) $projectId;
    }

    private function nullableScopedContractId(BudgetVersion $version, mixed $contractId): ?int
    {
        if ($contractId === null || $contractId === '') {
            return null;
        }

        $exists = Contract::query()->where('organization_id', $version->organization_id)->where('id', (int) $contractId)->exists();
        if (!$exists) {
            throw new \DomainException(trans_message('budgeting.lines.contract_not_found'));
        }

        return (int) $contractId;
    }

    private function nullableScopedCounterpartyId(BudgetVersion $version, mixed $counterpartyId): ?int
    {
        if ($counterpartyId === null || $counterpartyId === '') {
            return null;
        }

        $exists = Contractor::query()->where('organization_id', $version->organization_id)->where('id', (int) $counterpartyId)->exists();
        if (!$exists) {
            throw new \DomainException(trans_message('budgeting.lines.counterparty_not_found'));
        }

        return (int) $counterpartyId;
    }

    private function monthInsideVersion(BudgetVersion $version, string $month): string
    {
        $normalized = preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? "{$month}-01" : substr($month, 0, 7) . '-01';
        $date = CarbonImmutable::parse($normalized)->startOfMonth();
        $startsAt = CarbonImmutable::parse((string) $version->period->starts_at)->startOfMonth();
        $endsAt = CarbonImmutable::parse((string) $version->period->ends_at)->startOfMonth();

        if ($date->lessThan($startsAt) || $date->greaterThan($endsAt)) {
            throw new \DomainException(trans_message('budgeting.import_errors.month_out_of_period'));
        }

        return $date->toDateString();
    }

    private function monthValue(string $month): string
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return "{$month}-01";
        }

        return CarbonImmutable::parse($month)->startOfMonth()->toDateString();
    }
}
