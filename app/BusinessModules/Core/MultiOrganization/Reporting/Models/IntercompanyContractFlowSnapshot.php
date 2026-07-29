<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class IntercompanyContractFlowSnapshot extends Model
{
    protected $table = 'intercompany_contract_flow_snapshots';
    protected $guarded = [];
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'holding_id' => 'integer',
            'totals' => 'array',
            'source_refs' => 'array',
            'row_count' => 'integer',
            'generated_at' => 'immutable_datetime',
            'stale_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('report_snapshot_immutable'));
        static::deleting(static fn (): never => throw new LogicException('report_snapshot_immutable'));
    }
}
