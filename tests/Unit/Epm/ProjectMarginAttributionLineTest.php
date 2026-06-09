<?php

declare(strict_types=1);

namespace Tests\Unit\Epm;

use App\DTOs\Epm\ProjectMarginAttributionLine;
use App\Enums\Epm\ProjectMarginProblemFlag;
use App\Enums\Epm\ProjectMarginRiskFlag;
use PHPUnit\Framework\TestCase;

class ProjectMarginAttributionLineTest extends TestCase
{
    public function test_attribution_line_has_stable_contract_for_project_margin_report(): void
    {
        $this->assertTrue(class_exists(ProjectMarginAttributionLine::class));

        $line = new ProjectMarginAttributionLine(
            lineId: 'attr:contract_performance_act:9001:line:15',
            component: 'revenue_accrual',
            direction: 'income',
            organizationId: 42,
            projectId: 1001,
            stageId: 'schedule_task:551',
            contractId: 7001,
            actId: 9001,
            budgetArticleId: 'article-revenue',
            responsibilityCenterId: null,
            counterpartyId: 501,
            period: '2026-01',
            recognitionDate: '2026-01-31',
            recognitionEvent: 'act_approved',
            attributionRule: 'direct',
            currency: 'RUB',
            amountWithoutVat: 4200000.0,
            vatAmount: 840000.0,
            managementAmount: 4200000.0,
            managementCurrency: 'RUB',
            sourceType: 'contract_performance_act',
            sourceId: 9001,
            sourceLineId: 15,
            sourceDocumentNumber: 'АКТ-15',
            documentDate: '2026-01-31',
            source: [
                'type' => 'contract_performance_act',
                'id' => 9001,
                'line_id' => 15,
                'document_number' => 'АКТ-15',
                'document_date' => '2026-01-31',
                'domain' => 'contracts',
                'origin' => 'prohelper',
                'drill_down_available' => true,
                'href' => '/api/v1/admin/epm/project-margin/drill-down?line_id=attr%3Acontract_performance_act%3A9001%3Aline%3A15',
            ],
            confirmation: [
                'status' => 'pending',
                'workflow_status' => 'approved',
                'primary_document_status' => 'present',
                'edo_status' => 'pending',
                'one_c_status' => 'pending',
                'bank_status' => 'not_required',
                'message' => 'Внешнее подтверждение ожидает проверки',
            ],
            freshness: [
                'status' => 'fresh',
                'source_updated_at' => '2026-06-09T11:30:00+03:00',
                'external_synced_at' => '2026-06-09T10:15:00+03:00',
                'calculated_at' => '2026-06-09T12:00:00+03:00',
                'fresh_until' => '2026-06-09T13:00:00+03:00',
            ],
            reconciliation: [
                'status' => 'pending',
                'one_c' => 'pending',
                'bank' => 'not_applicable',
                'edo' => 'pending',
                'primary_documents' => 'actual',
                'workflow' => 'actual',
                'difference_amount' => 0.0,
                'difference_currency' => 'RUB',
            ],
            qualityStatus: 'attention',
            confirmationStatus: 'pending',
            freshnessStatus: 'fresh',
            reconciliationStatus: 'pending',
            problemFlags: [
                ProjectMarginProblemFlag::MissingResponsibilityCenter,
            ],
            riskFlags: [
                ProjectMarginRiskFlag::EdoPending,
            ],
            drillDown: [
                'available' => true,
                'href' => '/api/v1/admin/epm/project-margin/drill-down?line_id=attr%3Acontract_performance_act%3A9001%3Aline%3A15',
            ],
            permissions: [
                'details_available' => true,
                'hidden_amount' => 0.0,
                'hidden_rows_count' => 0,
            ],
        );

        $this->assertSame([
            'line_id' => 'attr:contract_performance_act:9001:line:15',
            'component' => 'revenue_accrual',
            'direction' => 'income',
            'organization_id' => 42,
            'project_id' => 1001,
            'stage_id' => 'schedule_task:551',
            'contract_id' => 7001,
            'act_id' => 9001,
            'budget_article_id' => 'article-revenue',
            'responsibility_center_id' => null,
            'counterparty_id' => 501,
            'period' => '2026-01',
            'recognition_date' => '2026-01-31',
            'recognition_event' => 'act_approved',
            'attribution_rule' => 'direct',
            'currency' => 'RUB',
            'amount_without_vat' => 4200000.0,
            'vat_amount' => 840000.0,
            'management_amount' => 4200000.0,
            'management_currency' => 'RUB',
            'source_type' => 'contract_performance_act',
            'source_id' => 9001,
            'source_line_id' => 15,
            'source_document_number' => 'АКТ-15',
            'document_date' => '2026-01-31',
            'source' => [
                'type' => 'contract_performance_act',
                'id' => 9001,
                'line_id' => 15,
                'document_number' => 'АКТ-15',
                'document_date' => '2026-01-31',
                'domain' => 'contracts',
                'origin' => 'prohelper',
                'drill_down_available' => true,
                'href' => '/api/v1/admin/epm/project-margin/drill-down?line_id=attr%3Acontract_performance_act%3A9001%3Aline%3A15',
            ],
            'confirmation' => [
                'status' => 'pending',
                'workflow_status' => 'approved',
                'primary_document_status' => 'present',
                'edo_status' => 'pending',
                'one_c_status' => 'pending',
                'bank_status' => 'not_required',
                'message' => 'Внешнее подтверждение ожидает проверки',
            ],
            'freshness' => [
                'status' => 'fresh',
                'source_updated_at' => '2026-06-09T11:30:00+03:00',
                'external_synced_at' => '2026-06-09T10:15:00+03:00',
                'calculated_at' => '2026-06-09T12:00:00+03:00',
                'fresh_until' => '2026-06-09T13:00:00+03:00',
            ],
            'reconciliation' => [
                'status' => 'pending',
                'one_c' => 'pending',
                'bank' => 'not_applicable',
                'edo' => 'pending',
                'primary_documents' => 'actual',
                'workflow' => 'actual',
                'difference_amount' => 0.0,
                'difference_currency' => 'RUB',
            ],
            'quality_status' => 'attention',
            'confirmation_status' => 'pending',
            'freshness_status' => 'fresh',
            'reconciliation_status' => 'pending',
            'problem_flags' => [
                'missing_responsibility_center',
            ],
            'risk_flags' => [
                'edo_pending',
            ],
            'drill_down' => [
                'available' => true,
                'href' => '/api/v1/admin/epm/project-margin/drill-down?line_id=attr%3Acontract_performance_act%3A9001%3Aline%3A15',
            ],
            'permissions' => [
                'details_available' => true,
                'hidden_amount' => 0.0,
                'hidden_rows_count' => 0,
            ],
        ], $line->toArray());
    }

    public function test_problem_and_risk_flag_values_match_specification(): void
    {
        $this->assertTrue(class_exists(ProjectMarginProblemFlag::class));
        $this->assertTrue(class_exists(ProjectMarginRiskFlag::class));

        $this->assertContains('missing_project', ProjectMarginProblemFlag::values());
        $this->assertContains('closed_period_change_required', ProjectMarginProblemFlag::values());
        $this->assertContains('hidden_by_permissions', ProjectMarginProblemFlag::values());
        $this->assertContains('cash_only_source', ProjectMarginRiskFlag::values());
        $this->assertContains('manual_adjustment_active', ProjectMarginRiskFlag::values());
        $this->assertContains('edo_pending', ProjectMarginRiskFlag::values());
    }
}
