<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\Models;

use App\Support\Reporting\ImmutableOwnerRecord;
use Illuminate\Database\Eloquent\Model;

final class CustomerSlaSnapshot extends Model
{
    use ImmutableOwnerRecord;

    protected $table = 'customer_sla_snapshots';

    protected $primaryKey = 'id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'scope_identity' => 'array',
        'filters' => 'array',
        'as_of' => 'immutable_datetime',
        'generated_at' => 'immutable_datetime',
        'stale_at' => 'immutable_datetime',
        'watermarks' => 'array',
        'row_count' => 'integer',
    ];
}
