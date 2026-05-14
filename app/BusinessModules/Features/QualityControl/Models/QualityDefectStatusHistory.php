<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Models;

use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityDefectStatusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'quality_defect_status_history';

    protected $fillable = [
        'quality_defect_id',
        'organization_id',
        'from_status',
        'to_status',
        'comment',
        'changed_by',
        'changed_at',
    ];

    protected $casts = [
        'from_status' => QualityDefectStatusEnum::class,
        'to_status' => QualityDefectStatusEnum::class,
        'changed_at' => 'datetime',
    ];

    public function defect(): BelongsTo
    {
        return $this->belongsTo(QualityDefect::class, 'quality_defect_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
