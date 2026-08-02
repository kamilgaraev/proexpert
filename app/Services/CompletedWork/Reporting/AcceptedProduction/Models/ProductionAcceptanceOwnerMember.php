<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductionAcceptanceOwnerMember extends Model
{
    public $timestamps = false;

    protected $table = 'production_acceptance_owner_members';

    protected $guarded = [];
}
