<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Models;

use Illuminate\Database\Eloquent\Model;

final class EmployeeRequest extends Model
{
    protected $table = 'workforce_employee_requests';

    protected $guarded = ['id'];

    protected $casts = ['requested_data' => 'array', 'comments' => 'array', 'result' => 'array'];
}
