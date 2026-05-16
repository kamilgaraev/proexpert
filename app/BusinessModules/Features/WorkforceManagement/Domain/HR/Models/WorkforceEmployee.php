<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $last_name
 * @property string $first_name
 * @property string|null $middle_name
 */
final class WorkforceEmployee extends Model
{
    use SoftDeletes;

    protected $table = 'workforce_employees';

    protected $fillable = [
        'organization_id',
        'user_id',
        'personnel_number',
        'last_name',
        'first_name',
        'middle_name',
        'employment_status',
        'hire_date',
        'dismissal_date',
        'external_payroll_ref',
        'phone',
        'email',
        'metadata',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'dismissal_date' => 'date',
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->last_name,
            $this->first_name,
            $this->middle_name,
        ])));
    }
}
