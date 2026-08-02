<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

final class SupplierAwardPostgresTest extends Waves23PostgresTestCase
{
    public function test_proposal_and_decision_versions_are_immutable_and_median_is_exact_decimal(): void
    {
        $this->assertTriggerExists('supplier_proposal_versions', 'supplier_proposal_versions_append_only');
        $this->assertTriggerExists(
            'supplier_award_decision_versions',
            'supplier_award_decision_versions_append_only',
        );
        self::assertSame(1, (int) $this->column('supplier_award_rows', 'median_amount_minor')->numeric_scale);
        self::assertSame(1, (int) $this->column('supplier_award_rows', 'median_variance_minor')->numeric_scale);
        self::assertNotNull($this->column('supplier_award_rows', 'supplier_party_id'));
        self::assertNotNull($this->column('supplier_award_rows', 'material_ids'));
        self::assertNotNull($this->column('supplier_award_decision_versions', 'dimension_hash'));
    }
}
