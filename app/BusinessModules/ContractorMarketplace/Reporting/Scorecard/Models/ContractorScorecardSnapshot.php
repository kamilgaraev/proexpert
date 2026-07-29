<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Models;

use App\Support\Reporting\ImmutableOwnerRecord;
use Illuminate\Database\Eloquent\Model;

final class ContractorScorecardSnapshot extends Model
{
    use ImmutableOwnerRecord;

    protected $table = 'contractor_scorecard_snapshots';

    protected $primaryKey = 'id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'policy_version_id' => 'integer',
        'scope_identity' => 'array',
        'filters' => 'array',
        'as_of' => 'immutable_datetime',
        'generated_at' => 'immutable_datetime',
        'stale_at' => 'immutable_datetime',
        'watermarks' => 'array',
        'row_count' => 'integer',
    ];
}
