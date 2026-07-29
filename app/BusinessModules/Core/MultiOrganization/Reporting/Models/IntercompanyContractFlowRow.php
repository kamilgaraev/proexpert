<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class IntercompanyContractFlowRow extends Model
{
    protected $table = 'intercompany_contract_flow_rows';
    protected $guarded = [];
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'project_id' => 'integer',
            'allocation_id' => 'integer',
            'counterparty_organization_id' => 'integer',
            'internal_minor' => 'integer',
            'external_minor' => 'integer',
            'unclassified_minor' => 'integer',
            'total_minor' => 'integer',
            'internal_share' => 'decimal:8',
            'external_share' => 'decimal:8',
            'unclassified_share' => 'decimal:8',
            'linked_spread_minor' => 'integer',
            'period_start' => 'immutable_date',
            'source_refs' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('report_row_immutable'));
        static::deleting(static fn (): never => throw new LogicException('report_row_immutable'));
    }
}
