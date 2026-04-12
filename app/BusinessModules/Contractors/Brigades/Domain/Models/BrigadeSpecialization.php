<?php

declare(strict_types=1);

namespace App\BusinessModules\Contractors\Brigades\Domain\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BrigadeSpecialization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
    ];
}
