<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio\DTO;

use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Support\PortfolioDecimal;
use InvalidArgumentException;

final readonly class ProjectPortfolioHealthRow
{
    public string $revenue;

    public string $cost;

    public string $margin;

    public ?string $marginPercent;

    public string $wip;

    public string $ftc;

    public string $eac;

    public string $ctc;

    public string $rowKey;

    public function __construct(
        public int $projectId,
        public string $projectName,
        public string $currency,
        string $revenue,
        string $cost,
        string $wip,
        string $ftc,
        string $eac,
        string $ctc,
        public string $riskLevel,
        public int $riskRank,
        public string $asOf,
        public array $sourceRefs,
    ) {
        if ($projectId < 1
            || trim($projectName) === ''
            || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
            || ! in_array($riskLevel, ['low', 'medium', 'high', 'critical'], true)
            || $riskRank < 1
            || $riskRank > 4
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $asOf) !== 1
            || ! array_is_list($sourceRefs)) {
            throw new InvalidArgumentException('project_portfolio_health_row_invalid');
        }

        $this->revenue = PortfolioDecimal::money($revenue);
        $this->cost = PortfolioDecimal::money($cost);
        $this->margin = PortfolioDecimal::subtract($this->revenue, $this->cost);
        $this->marginPercent = PortfolioDecimal::percent($this->margin, $this->revenue);
        $this->wip = PortfolioDecimal::money($wip);
        $this->ftc = PortfolioDecimal::money($ftc);
        $this->eac = PortfolioDecimal::money($eac);
        $this->ctc = PortfolioDecimal::money($ctc);
        $this->rowKey = implode(':', [$riskRank, $projectId, $currency, $asOf]);
    }

    public static function fromLegacy(array $row, string $asOf): self
    {
        $project = is_array($row['project'] ?? null) ? $row['project'] : [];
        $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
        $risk = is_string($row['risk_level'] ?? null) ? $row['risk_level'] : 'low';
        $ranks = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

        return new self(
            projectId: (int) ($project['id'] ?? 0),
            projectName: (string) ($project['name'] ?? ''),
            currency: (string) ($row['currency'] ?? ''),
            revenue: (string) ($metrics['revenue'] ?? '0'),
            cost: (string) ($metrics['cost'] ?? '0'),
            wip: (string) ($metrics['wip_total'] ?? $metrics['wip'] ?? '0'),
            ftc: (string) ($metrics['ftc'] ?? '0'),
            eac: (string) ($metrics['eac'] ?? '0'),
            ctc: (string) ($metrics['ctc'] ?? '0'),
            riskLevel: $risk,
            riskRank: $ranks[$risk] ?? 1,
            asOf: substr($asOf, 0, 10),
            sourceRefs: self::sourceRefs($row, (int) ($project['id'] ?? 0)),
        );
    }

    private static function sourceRefs(array $row, int $projectId): array
    {
        $sourceRefs = is_array($row['source_refs'] ?? null) && array_is_list($row['source_refs'])
            ? $row['source_refs']
            : [];
        $sourceRefs[] = ['type' => 'project', 'id' => $projectId];
        $unique = [];

        foreach ($sourceRefs as $sourceRef) {
            if (! is_array($sourceRef)
                || ! is_string($sourceRef['type'] ?? null)
                || (! is_int($sourceRef['id'] ?? null) && ! is_string($sourceRef['id'] ?? null))
                || trim((string) $sourceRef['id']) === '') {
                continue;
            }
            $id = $sourceRef['id'];
            if (is_int($id) && $id < 1) {
                continue;
            }
            $unique[$sourceRef['type'].':'.$id] = ['type' => $sourceRef['type'], 'id' => $id];
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }

    public function toArray(): array
    {
        return [
            'row_key' => $this->rowKey,
            'risk_rank' => $this->riskRank,
            'project_id' => $this->projectId,
            'project_name' => $this->projectName,
            'currency' => $this->currency,
            'revenue' => $this->revenue,
            'cost' => $this->cost,
            'margin' => $this->margin,
            'margin_percent' => $this->marginPercent,
            'wip' => $this->wip,
            'ftc' => $this->ftc,
            'eac' => $this->eac,
            'ctc' => $this->ctc,
            'risk' => $this->riskLevel,
            'as_of' => $this->asOf,
            'source_refs' => $this->sourceRefs,
        ];
    }
}
