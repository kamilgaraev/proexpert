<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Models;

use Illuminate\Database\Eloquent\Model;

final class AssetCategory extends Model
{
    protected $fillable = ['organization_id', 'name', 'normalized_name'];
}
