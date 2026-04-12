<?php

declare(strict_types=1);

namespace App\BusinessModules\Contractors\Brigades\Domain\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrigadeResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'brigade_id',
        'cover_message',
        'status',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(BrigadeRequest::class, 'request_id');
    }

    public function brigade(): BelongsTo
    {
        return $this->belongsTo(BrigadeProfile::class, 'brigade_id');
    }
}
