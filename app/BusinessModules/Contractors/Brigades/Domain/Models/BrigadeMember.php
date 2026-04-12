<?php

declare(strict_types=1);

namespace App\BusinessModules\Contractors\Brigades\Domain\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrigadeMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'brigade_id',
        'user_id',
        'full_name',
        'role',
        'phone',
        'is_manager',
        'is_active',
    ];

    protected $casts = [
        'is_manager' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function brigade(): BelongsTo
    {
        return $this->belongsTo(BrigadeProfile::class, 'brigade_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
