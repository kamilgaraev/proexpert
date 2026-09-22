<?php

declare(strict_types=1);

namespace Tests\Unit\CompletedWork;

use App\Services\CompletedWork\CompletedWorkReconciliationService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CompletedWorkReconciliationTest extends TestCase
{
    public function test_conflicting_quantities_preserve_originals_and_signed_act_evidence(): void
    {
        $rows = [$this->row(1, '100.000', '10.0000') + [
            'acts' => [['id' => 5, 'status' => 'signed', 'quantity' => '8.0000']],
        ]];
        $original = $rows;

        $report = (new CompletedWorkReconciliationService())->analyze($rows, 7, 11);

        self::assertSame($original, $rows);
        self::assertSame(['quantity_conflict'], $report[0]['issues']);
        self::assertSame('100.000', $report[0]['source']['quantity']);
        self::assertSame('10.0000', $report[0]['source']['completed_quantity']);
        self::assertSame($rows[0]['acts'], $report[0]['acts']);
        self::assertTrue($report[0]['protected_history']);
        self::assertTrue($report[0]['requires_manual_review']);
        self::assertNull($report[0]['resolved_quantity']);
    }

    public function test_missing_legacy_quantity_is_visible_without_silently_rewriting_it(): void
    {
        $report = (new CompletedWorkReconciliationService())->analyze([
            $this->row(1, '10.000', null),
        ], 7, 11);

        self::assertSame(['missing_completed_quantity'], $report[0]['issues']);
        self::assertNull($report[0]['source']['completed_quantity']);
        self::assertNull($report[0]['resolved_quantity']);
    }

    public function test_equal_decimals_are_not_conflicts_and_analysis_is_repeatable(): void
    {
        $rows = [$this->row(1, '10.000', '10.0000')];
        $service = new CompletedWorkReconciliationService();

        $report = $service->analyze($rows, 7, 11);

        self::assertSame([], $report[0]['issues']);
        self::assertSame('10.0000', $report[0]['resolved_quantity']);
        self::assertSame($report, $service->analyze($rows, 7, 11));
    }

    public function test_duplicate_journal_volume_is_reported_but_different_volumes_are_not(): void
    {
        $report = (new CompletedWorkReconciliationService())->analyze([
            $this->row(1, '10', '10') + ['journal_work_volume_id' => 22],
            $this->row(2, '10', '10') + ['journal_work_volume_id' => 22],
            $this->row(3, '10', '10') + ['journal_work_volume_id' => 23],
        ], 7, 11);

        self::assertSame(['duplicate_journal_volume'], $report[0]['issues']);
        self::assertSame([1, 2], $report[0]['duplicate_work_ids']);
        self::assertSame([1, 2], $report[1]['duplicate_work_ids']);
        self::assertSame([], $report[2]['issues']);
    }

    public function test_invalid_or_negative_quantity_is_a_finding_not_a_guessed_zero(): void
    {
        $report = (new CompletedWorkReconciliationService())->analyze([
            $this->row(1, '-1', '-1'),
            $this->row(2, 'not-a-number', '2'),
            $this->row(3, '9223372036854775807', '1'),
        ], 7, 11);

        foreach ($report as $finding) {
            self::assertContains('invalid_quantity', $finding['issues']);
            self::assertNull($finding['resolved_quantity']);
        }
    }

    public function test_another_project_or_organization_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $row = $this->row(1, '10', '10');
        $row['project_id'] = 12;

        (new CompletedWorkReconciliationService())->analyze([$row], 7, 11);
    }

    public function test_duplicate_input_ids_are_rejected_instead_of_multiplying_findings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $row = $this->row(1, '10', '10');

        (new CompletedWorkReconciliationService())->analyze([$row, $row], 7, 11);
    }

    private function row(int $id, string $quantity, ?string $completed): array
    {
        return [
            'id' => $id,
            'organization_id' => 7,
            'project_id' => 11,
            'quantity' => $quantity,
            'completed_quantity' => $completed,
        ];
    }
}
